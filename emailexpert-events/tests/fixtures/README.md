# Fixtures

No live API key was available during the build, so no real captured
responses exist yet. These fixtures encode the **assumed** HeySummit v2
shapes from `src/Api/Shapes.php` (see `docs/api-notes.md`), including
deliberate shape variations the mappers must tolerate (nested vs flat,
string vs integer IDs, missing optionals).

When the operator captures real (redacted) responses after installation —
via the discovery diagnostic log entries and webhook capture mode — replace
or add files here and re-run the suite.
