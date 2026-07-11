<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Support\SourceDownloader;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesAssets;
use Facades\Statamic\Fields\Validator as FieldValidator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Statamic\Assets\AssetUploader;
use Statamic\Contracts\Assets\AssetContainer as AssetContainerContract;
use Statamic\Rules\AllowedFile;

#[Name('assets_upload')]
#[Description('Upload a file into an asset container, from a source_url (the server downloads it — http/https, public hosts only) or inline content_base64 (small files only). filename is required with content_base64, optional with source_url (derived from the URL). Optional folder (e.g. "blog/2026") is created on demand. Existing paths are never overwritten — a collision is an error. Uploads are live immediately; set alt text afterwards with assets_update.')]
class AssetsUpload extends Tool
{
    use ResolvesAssets;

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
        // Re-check the registration gate: stale client tool caches are a
        // documented UX wart, not a security hole (spec §6 layer 1).
        $this->ensureWritesEnabled();

        // laravel/mcp doesn't enforce the JSON schema server-side (T10) —
        // validate shapes before touching them.
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

        // The CP's only upload gate (AssetPolicy::store, 6.x): v6 removed the
        // per-container allow_uploads toggle — permission is the whole story.
        $this->ensurePermission($user, "upload {$handle} assets");

        $folder = $this->normalizeFolder($validated['folder'] ?? null);

        [$contents, $derivedName] = $sourceUrl !== null
            ? app(SourceDownloader::class)->download($sourceUrl)
            : [$this->decodeBase64($base64), null];

        // CP parity: the same filename sanitizer the CP's store path applies.
        $basename = AssetUploader::getSafeFilename(
            $this->resolveBasename($validated['filename'] ?? null, $derivedName),
        );

        $path = ltrim(($folder === null ? '' : $folder.'/').$basename, '/');

        // No silent overwrite: the CP 409s here and asks a human; we refuse
        // and tell the agent its options (spec §3). Also keeps Statamic's
        // exists-therefore-timestamp rename fallback from ever firing.
        if ($existing = $this->findAsset($container, $path)) {
            throw new ToolException(sprintf(
                "asset '%s' already exists in container '%s' (id '%s') — pick another filename, or delete it first if it should be replaced",
                $path,
                $handle,
                $existing->id(),
            ));
        }

        $file = $this->makeUploadedFile($contents, $basename);

        try {
            $this->validateAgainstContainerRules($container, $file);

            // upload() is the CP path: cancellable AssetCreating, SVG
            // sanitization, meta generation, AssetUploaded/AssetCreated.
            // false = a listener cancelled — never report success for it.
            $asset = $container->makeAsset($path)->upload($file);

            if ($asset === false) {
                throw new ToolException('the upload was cancelled by a listener on this site — nothing was created');
            }
        } finally {
            @unlink($file->getPathname());
        }

        return $this->json([
            ...$this->assetSummary($asset),
            ...$this->liveness($asset, self::LIVENESS_UPLOADED),
        ]);
    }

    private function decodeBase64(string $encoded): string
    {
        $contents = base64_decode($encoded, strict: true);

        if ($contents === false || $contents === '') {
            throw new ToolException('content_base64 is not valid base64 — encode the raw file bytes');
        }

        $maxKb = (int) config('statamic.mcp.uploads.max_size', 10240);

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

        file_put_contents($temp, $contents);

        // test: true because these bytes never arrived via PHP's upload
        // machinery — without it isValid() fails and Statamic refuses them.
        return new UploadedFile($temp, $basename, test: true);
    }

    /**
     * The CP's own upload gate (AssetsController@store, 6.x): Statamic's
     * global AllowedFile denylist plus the container's configured rules.
     */
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
            ));
        }
    }
}
