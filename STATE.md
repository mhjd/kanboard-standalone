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
10. [x] Verify fixture task positions in fixture and round-trip scripts.
11. [x] Verify swimlane metadata and task swimlane mapping in fixture and round-trip scripts.
12. [x] Verify fixture task descriptions in fixture and round-trip scripts.
13. [x] Verify fixture project metadata in fixture and round-trip scripts.
14. [x] Verify fixture task and comment timestamps in fixture and round-trip scripts.
15. [x] Add a docker-based ci-docker Makefile target alias for verification.
16. [x] Verify fixture project_id mapping for columns, tasks, and swimlanes in fixture and round-trip scripts.
17. [x] Verify fixture task/comment user ownership mapping in fixture and round-trip scripts.
18. [x] Verify fixture project_has_users membership mapping in fixture and round-trip scripts.
19. [x] Verify fixture column metadata (task_limit, hide_in_dashboard) in fixture and round-trip scripts.
20. [x] Verify fixture project owner and last_modified metadata in fixture and round-trip scripts.
21. [x] Verify fixture comment reference values in fixture and round-trip scripts.
22. [x] Verify fixture task is_active flags in fixture and round-trip scripts.
23. [x] Verify fixture task priority values in fixture and round-trip scripts.
24. [x] Verify fixture subtask positions in fixture and round-trip scripts.
25. [x] Verify fixture task reference values in fixture and round-trip scripts.
26. [x] Verify fixture task due dates in fixture and round-trip scripts.
27. [x] Verify fixture task external_provider/external_uri values in fixture and round-trip scripts.
28. [x] Verify fixture schema_version matches Schema\VERSION when present in fixture and round-trip scripts.
29. [x] Verify fixture task category mapping in fixture and round-trip scripts.
30. [x] Verify fixture tag mapping in fixture and round-trip scripts.
31. [x] Verify fixture task start dates in fixture and round-trip scripts.
32. [x] Verify fixture task time tracking values (time_spent, time_estimated) in fixture and round-trip scripts.
33. [x] Verify fixture task completion dates (date_completed) in fixture and round-trip scripts.
34. [x] Verify fixture task score values in fixture and round-trip scripts.
35. [x] Verify fixture comment privacy flags (is_private) in fixture and round-trip scripts.
36. [x] Verify fixture user metadata (username/role/is_admin/is_active) in fixture and round-trip scripts.
37. [x] Verify fixture subtask time tracking values in fixture and round-trip scripts.
38. [x] Verify fixture task is_milestone flags in fixture and round-trip scripts.
39. [x] Verify fixture subtask user ownership mapping in fixture and round-trip scripts.
40. [x] Verify fixture task recurrence defaults in fixture and round-trip scripts.
41. [x] Verify fixture project token values in fixture and round-trip scripts.
42. [x] Verify fixture user profile fields (name/email/timezone/language) in fixture and round-trip scripts.
