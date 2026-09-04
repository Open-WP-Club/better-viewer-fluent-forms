# Better Viewer for Fluent Forms

> A small, client-friendly WordPress admin interface for Fluent Forms submissions.

[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b?logo=wordpress)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4?logo=php)](https://www.php.net/)
[![Fluent%20Forms](https://img.shields.io/badge/Requires-Fluent%20Forms-4b2e83)](https://fluentforms.com/)

**Repository:** [Open-WP-Club/better-viewer-fluent-forms](https://github.com/Open-WP-Club/better-viewer-fluent-forms)

---

## Overview

Better Viewer for Fluent Forms replaces the busy Fluent Forms Entries screen with a focused area for people who simply need to review and process incoming enquiries.

It uses Fluent Forms as the source of truth. Submission responses are never copied to a separate table. The only additional data is a lightweight Fluent Forms submission-meta value that stores the client workflow status.

## Features

- Clean top-level **Enquiries** admin menu with a responsive, readable table.
- Server-side pagination, search, date range filtering, and status filtering.
- Field-specific search, read/unread filters, and quick date periods.
- Native Fluent Forms read/unread tracking with an unread menu counter.
- CSV export of the current filtered results.
- Bulk read/unread and workflow status actions.
- Dashboard counters for total, unread, new, in-progress, and completed enquiries.
- One-click email, phone, and WhatsApp actions detected from submission data.
- Internal notes with author and timestamp.
- Admin email notifications for new enquiries from configured forms.
- Non-destructive duplicate-contact and multi-link spam warnings.
- Four simple workflow statuses: **New**, **In progress**, **Completed**, and **Archived**.
- A distraction-free submission detail screen with labelled sections.
- All read and unread submissions are listed; only trashed entries are omitted.
- Clickable links for uploaded files and URL values.
- Support for multiple Fluent Forms, displayed as tabs.
- A visual field picker: choose fields, client-facing labels, list visibility, detail visibility, and sections—without writing JSON or PHP configuration.
- Field labels are copied from Fluent Forms automatically and remain editable.
- English base language and included Bulgarian (`bg_BG`) translation.
- Capability checks, Fluent Forms ACL checks, nonces for every AJAX request, sanitised input, and escaped output.

## Requirements

- WordPress 6.0 or newer recommended
- PHP 7.4 or newer
- [Fluent Forms](https://wordpress.org/plugins/fluentform/) installed and activated

## Installation

### From GitHub

1. Download the latest repository ZIP, or clone the repository:

   ```bash
   git clone https://github.com/Open-WP-Club/better-viewer-fluent-forms.git
   ```

2. Place the repository contents in a dedicated directory under `wp-content/plugins/`, for example:

   ```text
   wp-content/plugins/better-viewer-fluent-forms/
   ```

3. Activate **Client Fluent Submissions** in **Plugins → Installed Plugins**.
4. Go to **Enquiries → Settings** and configure one or more Fluent Forms.

## Configure a form

1. Open **Enquiries → Settings**.
2. Click **Add form**.
3. Select one of the available Fluent Forms forms. Its fields load automatically.
4. For each available field, set the client-facing label and select whether it should appear in the list and/or the detail view.
5. Assign detail fields to sections, such as `Contact`, `Information`, or `Message`.
6. Save the settings.

The mapping is stored in the WordPress option `cfs_forms_config`. The screen builds the internal mapping automatically; no custom configuration file is needed.

## Permissions

The plugin intentionally does not treat “administrator” as the only access rule. A user must have both the WordPress capability and the corresponding Fluent Forms permission for the selected form.

| Action | WordPress capability | Fluent Forms permission |
| --- | --- | --- |
| View enquiries | `cfs_view_submissions` | `fluentform_entries_viewer` |
| Update a status | `cfs_manage_submissions` | `fluentform_manage_entries` |
| Change plugin settings | `cfs_manage_submissions` | Form viewer permission when loading fields |

On activation, administrators receive the two WordPress capabilities. You can assign them to another role or user deliberately; Fluent Forms’ own per-form access restrictions still apply.

## Data and statuses

Submissions remain in Fluent Forms. When a client status is changed, the plugin stores only this meta value against that submission:

```text
_cfs_client_status = new | in_progress | completed | archived
```

Existing submissions without this meta value are treated as **New**.

## Languages

The plugin’s source language is English. Bulgarian is bundled in `languages/client-fluent-submissions-bg_BG.mo` and loads automatically when the site language is Bulgarian (`bg_BG`).

## Project structure

```text
client-fluent-submissions.php  Plugin bootstrap
includes/
  admin-page.php               Admin pages, settings UI, AJAX endpoints
  config.php                   Configuration validation and persistence
  submissions.php              Fluent Forms models and status handling
assets/
  admin.css                    Admin styling
  admin.js                     Field picker and status interaction
languages/                     Translation files
```

## Development notes

- The integration uses Fluent Forms’ `Submission`, `SubmissionMeta`, `Form`, and `FormFieldsParser` classes rather than a duplicated submissions table.
- The list uses page-sized queries plus a batch metadata lookup, avoiding an N+1 query pattern.
- Fluent Forms is checked before its classes are used. If it is unavailable, the plugin displays an admin notice instead of causing a fatal error.

## Manual test checklist

1. Activate Fluent Forms and this plugin.
2. Configure a form through **Enquiries → Settings**.
3. Open **Enquiries** and verify the configured table columns.
4. Test name/email/phone search, status filter, date filters, and pagination.
5. Open a submission and confirm that labels and sections are readable.
6. Update each status and confirm that the status persists after refresh.
7. Test with a user that has viewer access only, then a user with manage access.
8. Change the WordPress site language to Bulgarian and verify the translated UI.
