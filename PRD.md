# PRD.md — Kanboard Standalone Desktop (Draft v0.1)

## 1. Summary

We need a **standalone desktop application** distributed as an **executable binary** (no external server setup) that can **read and write Kanboard data** in a way that remains compatible with Kanboard’s own storage/export format. The primary goal is to support a minimal subset of Kanboard features required by the requester:

* Card colors
* Project columns (and column ordering)
* Task comments (included in import/export)
* Subtasks inside a task

The product is intentionally **not** a full Kanboard replacement. It is a focused offline client/editor for Kanboard-format data.

## 2. Context and Problem

Kanboard is a self-hosted, web-based kanban project management tool. It stores its data in a database, using **SQLite by default**, in a single file (`data/db.sqlite`). ([docs.kanboard.org][1])

The requester needs a version that does **not** operate in a classical client/server mode, but as a **standalone desktop app** (binary executable) that can open Kanboard data and persist changes in the same format.

Important constraint: the app must not “export to a different format and re-import later” as the primary workflow. The app must **natively operate on Kanboard-compatible data**.

## 3. Goals

### Product goals (MVP / P0)

1. **Open** an existing Kanboard dataset (at minimum a `db.sqlite` file, preferably a Kanboard-like `data/` folder).
2. Display a **board view per project** with:

   * Columns
   * Tasks/cards within columns
   * Card color rendering
3. Provide task details view and editing for:

   * Color
   * Comments (create/edit/delete; preserved in import/export)
   * Subtasks (create/edit/toggle status; preserved in import/export)
4. Support basic board interactions:

   * Move tasks between columns (drag & drop or equivalent)
   * Create/edit columns for a project (name + ordering)
5. **Export / backup** in a Kanboard-compatible way (see “Data Compatibility”).

### Secondary goals (P1)

* Basic search within a project (title/description/comments)
* Multi-project navigation
* Simple conflict detection when the DB is open elsewhere

## 4. Non-goals

* Multi-user collaboration
* Running Kanboard plugins
* Full feature parity with Kanboard (swimlanes, analytics, notifications, ACLs, etc.)
* Remote sync / server hosting features

## 5. Target Users and Use Cases

### Primary user

* A single user who needs an offline board editor/reader compatible with Kanboard data.

### Core use cases (MVP)

* Open an existing Kanboard DB file, see projects, open a project board.
* Move tasks across columns and persist the result.
* Change a task’s color and persist.
* Add comments to a task and ensure they remain present after export/import.
* Add and manage subtasks and ensure they remain present after export/import.

## 6. Data Compatibility (Definition of “Kanboard data format”)

### Baseline definition (preferred)

The app’s canonical persisted format is **Kanboard’s SQLite database file** stored in `data/db.sqlite` (and optionally `db.sqlite.gz` as a backup artifact). ([docs.kanboard.org][1])

Why this baseline:

* Kanboard’s documentation describes SQLite as the default storage and a single-file database for tasks/projects/users. ([docs.kanboard.org][1])
* Backup/export in SQLite form is explicitly supported and common in Kanboard workflows. ([docs.kanboard.org][1])

### Import/Export requirements

* **Import**: open an existing `db.sqlite` (or `db.sqlite.gz` after decompression) and operate directly on it.
* **Export**: produce a copy of the database (optionally gzipped) and/or export a Kanboard-like `data/` folder.
* The app must not require a running Kanboard server to import/export.

Note: Kanboard also provides CSV exports for tasks/subtasks via CLI, which can be used as a verification tool but is not sufficient as a full-fidelity backup format. ([docs.kanboard.org][2])

### Version compatibility

* Initial compatibility target: **Kanboard 1.2.49** database format and behavior. ([kanboard.org][3])
* The project should define a policy for backward compatibility (e.g., “supports N-2 minor versions”) once schema variability is understood.

### SQLite specifics

* Kanboard enables SQLite WAL mode by default since v1.2.27; our app must handle safe reads/writes accordingly. ([docs.kanboard.org][1])

## 7. Architectural Constraint: “Not client-server”

Interpretation for this project:

* The desktop UI must interact with the data layer **in-process**.
* No required separate web server deployment.
* No dependence on network sockets for normal operation.

A webview-based UI (e.g., embedded browser UI) is acceptable **only if** it does not require running a user-managed server and does not expose an HTTP service as a required component of the architecture. (This is a constraint to evaluate during the spike.)

## 8. Approach Options (Fork vs Sidecar) — Decision Pending

We do not yet know whether we should:

* **(A) Fork Kanboard and adapt/package it**, or
* **(B) Build a separate standalone app** that reads/writes Kanboard-format data.

This PRD makes that uncertainty explicit and resolves it via a **Phase 0 spike** with objective criteria.

### Option A — Fork + package Kanboard

**Idea:** reuse most of Kanboard UI/business logic and package it into a desktop binary.

Pros:

* Maximum functional reuse
* Lower risk of subtle data-model mismatches (in theory)

Cons:

* Hard to satisfy “not client-server” if it relies on a local server model
* Packaging PHP + web stack into a clean desktop binary can be complex
* UX may remain “web app in a shell” rather than native desktop

### Option B — Sidecar standalone app operating on Kanboard SQLite

**Idea:** build a new desktop app with its own UI and data access layer, writing directly to Kanboard-compatible SQLite.

Pros:

* Naturally fits “standalone / not client-server”
* Can keep the scope tight (MVP subset only)
* Packaging story can be simpler depending on tech choice

Cons:

* Must match Kanboard DB schema and semantics precisely
* Risk of schema drift across Kanboard versions

### Option C — Sidecar app + vendor Kanboard schema/migrations as a reference

**Idea:** build a new app, but **vendor or submodule** Kanboard to reuse/execute migrations or validate schema version.

Pros:

* Reduces schema drift risk
* Lets us keep Kanboard upstream as “source of truth”
* Still allows non-client-server operation

Cons:

* Requires careful integration boundaries
* Still some complexity in running/porting migrations

### Decision criteria (how we choose)

We pick the option that best satisfies:

1. Compliance with “standalone, not client-server”
2. Data compatibility confidence (low corruption risk)
3. Maintainability vs upstream Kanboard changes
4. Time-to-MVP for the required feature subset

## 9. Milestones (Ralph-loop friendly)

### Phase 0 — Data format spike (must happen first)

Deliverables:

* Confirm exactly what “Kanboard data format” means in practice (SQLite baseline vs something else).
* Create **fixtures**:

  * A minimal `db.sqlite` with one project, multiple columns, tasks, comments, subtasks, colors.
* Create a **round-trip verification**:

  * Our app modifies the DB
  * Kanboard can open the resulting DB without errors and displays expected data
* Output: an ADR (“Approach decision”) selecting Option A/B/C.

Notes:

* Kanboard upgrades are commonly done by copying the `data/` folder and running migrations automatically. This suggests schema migration handling matters for long-term compatibility. ([docs.kanboard.org][4])

### Phase 1 — MVP read-only board

* Open DB, list projects, show board with columns + tasks + colors.

### Phase 2 — Editing tasks + comments + subtasks

* Persist:

  * task color
  * comments
  * subtasks

### Phase 3 — Column editing + task movement

* Create/rename/reorder columns
* Move tasks between columns

### Phase 4 — Packaging + release artifact

* Produce binaries for target OSes (define scope)
* Smoke tests and basic UX polish

## 9.1 Remaining Work Checklist (derived from PRD)

- [ ] Phase 1: MVP read-only board (open DB, list projects, show board with columns + tasks + colors).
- [ ] Phase 2: Editing tasks + comments + subtasks (persist task color, comments, subtasks).
- [ ] Phase 3: Column editing + task movement (create/rename/reorder columns; move tasks).
- [ ] Phase 4: Packaging + release artifact (define target OSes, produce binaries, smoke tests).
- [ ] Definition of Done: Kanboard opens edited DB with correct colors/columns/comments/subtasks; automated tests; build pipeline artifact.

## 10. Functional Requirements (MVP detail)

### Projects and navigation (P0)

* List projects found in DB
* Open a project board

### Board view (P0)

* Render columns in the correct order
* Render tasks/cards per column
* Show task title + color indicator

### Task operations (P0)

* Update task color
* Move task to a different column

### Comments (P0)

* List comments for a task
* Add comment
* Edit/delete comment (if Kanboard supports these semantics in DB; otherwise limit to append-only with clear UX)

### Subtasks (P0)

* List subtasks for a task
* Add subtask
* Toggle subtask status
* Edit subtask title

### Columns (P0)

* Add column
* Rename column
* Reorder columns

## 11. Quality, Safety, and Verification

### Definition of Done (MVP)

* The app can open a Kanboard `db.sqlite`, and after performing supported edits:

  * Kanboard opens the same DB successfully
  * Colors/columns/comments/subtasks remain correct
* Automated tests:

  * Unit tests for DB layer
  * Integration tests for core flows
* Build pipeline produces a working binary artifact

### Data corruption prevention

* All DB writes must be transactional.
* The app must behave safely with WAL mode (read/write without breaking the DB). ([docs.kanboard.org][1])
* Consider DB locking strategy and user messaging if DB is in use elsewhere.

### Compatibility smoke checks (recommended)

* Use Kanboard’s own local tooling (e.g., CLI exports for tasks/subtasks) as a secondary verification signal. ([docs.kanboard.org][2])

## 12. Risks and Mitigations

### Risk: schema drift across Kanboard versions

Mitigation:

* Pin a baseline version (1.2.49)
* Implement schema version checks
* Consider vendoring Kanboard migrations or using Kanboard as a reference (Option C)

### Risk: “not client-server” interpretation mismatch

Mitigation:

* Write down an explicit interpretation (Section 7)
* Confirm with requester early using the Phase 0 ADR

### Risk: scope creep

Mitigation:

* Keep MVP limited to the four required feature families
* Track all extras as P1+ backlog

[1]: https://docs.kanboard.org/v1/admin/sqlite/ "SQLite - Kanboard Documentation"
[2]: https://docs.kanboard.org/v1/admin/cli/ "Command Line Interface - Kanboard Documentation"
[3]: https://kanboard.org/releases.html?utm_source=chatgpt.com "Kanboard Releases"
[4]: https://docs.kanboard.org/v1/admin/upgrade/?utm_source=chatgpt.com "Upgrading to a New Version"
[5]: https://github.com/kanboard/kanboard/blob/main/LICENSE?utm_source=chatgpt.com "kanboard/LICENSE at main"
[6]: https://github.com/kanboard/kanboard?utm_source=chatgpt.com "kanboard/kanboard: Kanban project management software"
