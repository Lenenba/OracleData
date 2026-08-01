<?php

test('query graph capabilities are disabled by default in the test environment', function () {
    expect(config('fusion.query_graph.resource_graph_enabled'))->toBeFalse()
        ->and(config('fusion.query_graph.authoring_enabled'))->toBeFalse()
        ->and(config('fusion.query_graph.execution_enabled'))->toBeFalse();
});
