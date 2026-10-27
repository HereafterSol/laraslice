# Naming policy — public docs and marketing

**Rule:** Do **not** use third-party **product**, **vendor**, or **competitor** names in user-facing framework documentation, landing pages, skills, or README — even when describing a port benchmark.

Use **generic** terms instead:

| Avoid (examples) | Prefer |
| --- | --- |
| Named ERP / CRM products | **source ERP**, **legacy monolith**, **benchmark application**, **reference source** |
| Named admin themes / kits | **legacy admin UI**, **third-party admin kit**, **jQuery-era shell** |
| Named payment / SaaS brands in examples | **payment provider**, **enterprise SaaS**, **example integration** |
| Named alternate port targets | **non-Laravel legacy app**, **SQL-dump source** |

**Allowed:**

- **Our** product names: LaraSlice, NodeSlice, BlatUI, Hereafter Solutions, Slice Studio, etc.
- **Internal paths** in runbooks (`laragon/www/erp`) when needed for developers on the team — pair with “source ERP” in prose.
- **CHANGELOG / historical** entries may name sources **once** with date context; new edits should genericize when touched.

**Ports:** In `REPLICA-STATUS.md`, describe the source as **“production Laravel ERP (benchmark)”** or **“source ERP (:7001)”** unless a legal/license note requires the real name in a private repo only.

**AI agents:** Follow this file when writing or editing LaraSlice docs and port status files committed to the public package.
