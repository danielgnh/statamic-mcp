<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Support\SourceDownloader;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesAssets;
use Facades\Statamic\Fields\Validator as FieldValidator;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Exceptions\DecoderException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use Statamic\Assets\AssetUploader;
use Statamic\Assets\Uploader;
use Statamic\Contracts\Assets\AssetContainer as AssetContainerContract;
use Statamic\Rules\AllowedFile;

#[Name('assets_upload')]
#[Description('Upload a file into an asset container, from a source_url (the server downloads it — http/https, public hosts only) or inline content_base64 (small files only). filename is required with content_base64, optional with source_url (derived from the URL). Optional folder (e.g. "blog/2026") is created on demand. Existing paths are never overwritten — a collision is an error. Uploads are live immediately; set alt text afterwards with assets_update.')]
class AssetsUpload extends Tool
{
    use ResolvesAssets;

    private const int MAX_FILENAME_BYTES = 250;

    private const string UNREADABLE_IMAGE = "image presets — the file is stored, but Statamic could not read it as an image, and the site's log has the error. Check it, and delete it if it is wrong.";

    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'container' => $schema->string()->description('Asset container handle — see statamic_overview.')->required(),
            'source_url' => $schema->string()->description('Public http(s) URL to download. Exactly one of source_url / content_base64.'),
            'content_base64' => $schema->string()->description('Base64-encoded file contents, for small files. Exactly one of source_url / content_base64.'),
            'filename' => $schema->string()->description("Target filename with extension, e.g. 'hero.jpg'. Required with content_base64; with source_url it defaults to the URL's basename."),
            'folder' => $schema->string()->description("Destination folder inside the container, e.g. 'blog/2026'. Defaults to the container root; created on demand."),
        ];
    }

    public function shouldRegister(Request $request): bool
    {
        return $this->writesEnabled();
    }

    protected function execute(Request $request): Response
    {
        $this->ensureWritesEnabled();

        $validated = $request->validate(
            [
                'container' => 'required|string',
                'source_url' => 'nullable|string',
                'content_base64' => 'nullable|string',
                'filename' => 'nullable|string',
                'folder' => 'nullable|string',
            ],
            ['container.required' => 'Pass an asset container handle, e.g. "images" — see statamic_overview.'],
        );

        $sourceUrl = $validated['source_url'] ?? null;
        $base64 = $validated['content_base64'] ?? null;

        if (($sourceUrl === null) === ($base64 === null)) {
            throw new ToolException('pass exactly one of source_url or content_base64');
        }

        $handle = $validated['container'];
        $container = $this->resolveContainer($handle);

        $user = $this->user($request);

        $this->ensurePermission($user, "upload {$handle} assets");

        $folder = $this->normalizeFolder($validated['folder'] ?? null);

        [$contents, $derivedName] = $sourceUrl !== null
            ? app(SourceDownloader::class)->download($sourceUrl)
            : [$this->decodeBase64($base64), null];

        $basename = AssetUploader::getSafeFilename(
            $this->resolveBasename($validated['filename'] ?? null, $derivedName),
        );

        if (strlen($basename) > self::MAX_FILENAME_BYTES) {
            throw new ToolException(sprintf('filename is longer than %d bytes — pick a shorter one', self::MAX_FILENAME_BYTES));
        }

        $path = ltrim(($folder === null ? '' : $folder.'/').$basename, '/');

        if ($existing = $this->findAsset($container, $path)) {
            throw new ToolException(sprintf(
                "asset '%s' already exists in container '%s' (id '%s') — pick another filename, or delete it first if it should be replaced",
                $path,
                $handle,
                $existing->id(),
            ));
        }

        $file = $this->makeUploadedFile($contents, $basename);
        $asset = $container->makeAsset($path);
        $unreadable = false;

        try {
            $this->validateAgainstContainerRules($container, $file);

            if ($asset->upload($file) === false) {
                throw new ToolException('the upload was cancelled by a listener on this site — nothing was created');
            }
        } catch (UnableToReadFile|DecoderException $e) {
            report($e);

            $unreadable = true;
        } catch (UnableToWriteFile $e) {
            if (! $this->thrownByTheUploadersWrite($e)) {
                throw $e;
            }

            report($e);

            if ($container->disk()->exists($e->location())) {
                $container->disk()->delete($e->location());
            }

            throw $this->notStored($path);
        } finally {
            @unlink($file->getPathname());
        }

        if (! $container->disk()->exists($asset->path())) {
            $asset->deleteQuietly();

            throw $this->notStored($path);
        }

        return $this->json([
            ...$this->assetSummary($asset),
            ...$this->liveness($asset, self::LIVENESS_UPLOADED),
            ...($unreadable ? ['not_stored' => [self::UNREADABLE_IMAGE]] : []),
        ]);
    }

    private function thrownByTheUploadersWrite(UnableToWriteFile $e): bool
    {
        $frames = collect($e->getTrace())->takeUntil(fn (array $frame): bool => ($frame['class'] ?? null) === self::class);

        return $frames->contains(fn (array $frame): bool => ($frame['class'] ?? null) === Uploader::class && $frame['function'] === 'write')
            && ! $frames->contains(fn (array $frame): bool => is_a($frame['class'] ?? '', Dispatcher::class, true));
    }

    private function notStored(string $path): ToolException
    {
        return new ToolException(sprintf("the disk did not store '%s' — nothing was created. It may be read-only, or out of space.", $path));
    }

    private function decodeBase64(string $encoded): string
    {
        $contents = base64_decode($encoded, strict: true);

        if ($contents === false || $contents === '') {
            throw new ToolException('content_base64 is not valid base64 — encode the raw file bytes');
        }

        $maxKb = SourceDownloader::maxKilobytes();

        if (strlen($contents) > $maxKb * 1024) {
            throw new ToolException(sprintf('decoded file exceeds the %d KB limit (statamic.mcp.uploads.max_size)', $maxKb));
        }

        return $contents;
    }

    private function resolveBasename(?string $filename, ?string $derived): string
    {
        $basename = $filename !== null && $filename !== '' ? $filename : $derived;

        if ($basename === null || $basename === '') {
            throw new ToolException('pass filename — it could not be derived from the source_url');
        }

        if (str_contains($basename, '/') || str_contains($basename, '\\') || str_contains($basename, '..')) {
            throw new ToolException("filename must be a bare name like 'hero.jpg' — use folder for the destination path");
        }

        if (pathinfo($basename, PATHINFO_EXTENSION) === '') {
            throw new ToolException("filename needs an extension, e.g. 'hero.jpg' — the container's rules and Statamic's file guards key off it");
        }

        return $basename;
    }

    private function makeUploadedFile(string $contents, string $basename): UploadedFile
    {
        $temp = tempnam(sys_get_temp_dir(), 'statamic-mcp-upload-');

        if ($temp === false) {
            throw new ToolException('could not stage the upload to a temp file — check disk space on the server');
        }

        if (file_put_contents($temp, $contents) !== strlen($contents)) {
            @unlink($temp);

            throw new ToolException('could not stage the upload to a temp file — check disk space on the server');
        }

        return new UploadedFile($temp, $basename, test: true);
    }

    private function validateAgainstContainerRules(AssetContainerContract $container, UploadedFile $file): void
    {
        $rules = collect($container->validationRules())
            ->map(fn ($rule) => FieldValidator::parse($rule))
            ->all();

        try {
            Validator::make(
                ['file' => $file],
                ['file' => array_merge(['file', new AllowedFile], $rules)],
            )->validate();
        } catch (ValidationException $e) {
            throw new ToolException('upload validation failed: '.json_encode(
                $e->errors(),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ), $e->getCode(), $e);
        }
    }
}
