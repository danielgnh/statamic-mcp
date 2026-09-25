<?php

use Danielgnh\StatamicMcp\Http\Controllers\McpConnectionsController;
use Danielgnh\StatamicMcp\Http\Controllers\McpController;
use Danielgnh\StatamicMcp\Http\Controllers\McpGuidelinesController;
use Danielgnh\StatamicMcp\Http\Controllers\McpTokensController;
use Illuminate\Support\Facades\Route;

// Statamic loads this file on its own, whatever the addon's config says, so
// the kill switch is checked here, each time routes load.
if (! config('statamic.mcp.enabled')) {
    return;
}

Route::prefix('mcp')->name('mcp.')->middleware('can:access mcp')->group(function () {
    Route::get('/', [McpController::class, 'index'])->name('index');

    Route::get('guidelines', [McpGuidelinesController::class, 'edit'])->name('guidelines.edit');
    Route::patch('guidelines', [McpGuidelinesController::class, 'update'])->name('guidelines.update');

    Route::get('connections', [McpController::class, 'connections'])->name('connections.index');
    Route::post('connections/tokens', [McpTokensController::class, 'store'])->name('connections.tokens.store');
    Route::delete('connections/tokens/{tokenId}', [McpTokensController::class, 'destroy'])->name('connections.tokens.destroy');
    Route::delete('connections/oauth/{clientId}/{userId}', [McpConnectionsController::class, 'destroy'])->name('connections.oauth.destroy');
});
