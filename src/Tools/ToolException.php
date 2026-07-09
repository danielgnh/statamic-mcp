<?php

namespace Danielgnh\StatamicMcp\Tools;

use RuntimeException;

/**
 * Thrown by base-Tool guards (ensureExposed, ensurePermission, and tool-level
 * failures); rendered as Response::error() by Tool::handle().
 */
class ToolException extends RuntimeException {}
