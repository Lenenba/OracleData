<?php

namespace App\Exceptions;

use App\Services\QueryAgent;
use RuntimeException;

/**
 * Raised inside {@see QueryAgent} when a cooperative cancellation
 * was requested between two iterations, so the queued job can stop cleanly.
 */
class AgentAnalysisCancelled extends RuntimeException {}
