<?php

namespace Danielgnh\StatamicMcp\Http\Controllers;

use Danielgnh\StatamicMcp\Support\AgentGuidelines;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Statamic\CP\PublishForm;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

/**
 * The Guidelines page under Tools → MCP. Only super admins reach it: what agents
 * read here shapes everything they write on the site.
 */
class McpGuidelinesController extends CpController
{
    public function edit(AgentGuidelines $guidelines): View
    {
        abort_unless(User::current()?->isSuper(), 403);

        $blueprint = $guidelines->blueprint();
        $fields = $blueprint->fields()->addValues($guidelines->values())->preProcess();

        return view('statamic-mcp::mcp.guidelines', [
            'blueprint' => $blueprint->toPublishArray(),
            'values' => $fields->values()->all(),
            'meta' => $fields->meta()->all(),
        ]);
    }

    /**
     * @return array{saved: true}
     */
    public function update(Request $request, AgentGuidelines $guidelines): array
    {
        abort_unless(User::current()?->isSuper(), 403);

        $guidelines->save(PublishForm::make($guidelines->blueprint())->submit($request->all()));

        return ['saved' => true];
    }
}
