# STATE.md — Global Checklist

1. [x] Bootstrap Ralph loop metadata files (STATE.md, ARCHITECTURE.md, progress.txt, docs/adr/).
2. [x] Locate authoritative Kanboard import/export format and document sources.
3. [x] Create a minimal Kanboard SQLite fixture with project, columns, tasks, colors, comments, and subtasks.
4. [x] Add a round-trip test (import -> export) that preserves required entities.
5. [x] Decide approach (A/B/C) and record an ADR.
6. [x] Add Makefile targets dev/test/build/fixtures/ci (documented with comments).
7. [x] Validate fixture comment text and subtask status in verification scripts.
8. [x] Add SQLite integrity_check to fixture verification.
9. [x] Verify fixture task-to-column mapping in fixture and round-trip scripts.
