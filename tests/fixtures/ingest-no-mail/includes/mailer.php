<?php
// Fail closed if a future code path attempts to send from the offline fixture.
function send_email(...$args) { throw new LogicException('Mail is forbidden in the offline ingest fixture.'); }
