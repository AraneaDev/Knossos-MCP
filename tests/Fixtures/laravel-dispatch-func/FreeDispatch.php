<?php

namespace App;

final class FreeDispatch
{
    public function handle(): void
    {
        // dispatch() free function call — exercises the 'dispatch' name in
        // LaravelDispatchFactCollector::functionDispatch() with a New_ argument.
        dispatch(new SomeEvent());

        // event() free function call with a New_ argument.
        event(new AnotherEvent());
    }
}
