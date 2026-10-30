# VibePassQR WP 🎟️✨

> Event registration management, QR cards, secure card recovery, and attendance tracking in WordPress, built with **Vibe Coding** energy and just the right amount of seriousness for event day!

[![Latest Release](https://img.shields.io/github/v/release/maklf-dev/VibePassQR-wp?style=flat-square)](https://github.com/maklf-dev/VibePassQR-wp/releases/latest)
[![Download](https://img.shields.io/badge/Download-Latest%20ZIP-3A70A7?style=flat-square&logo=github)](https://github.com/maklf-dev/VibePassQR-wp/releases/latest/download/event-card-manager.zip)
[![WordPress](https://img.shields.io/badge/WordPress-6.5%2B-21759B?style=flat-square&logo=wordpress)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=flat-square&logo=php)](https://www.php.net/)
[![License](https://img.shields.io/badge/License-GPL--2.0--or--later-2EA44F?style=flat-square)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)

**VibePassQR WP** is the repository name for the **Event Card Manager** plugin, a multi-event WordPress solution for building registration forms, issuing cards and QR codes, recovering cards, managing guests, tracking attendance, and generating management reports.

The idea is simple: organizers should focus on the event itself, not wandering spreadsheets, lost QR codes, or the infamous "My name isn't on the list!" 😄

> [!IMPORTANT]
> The plugin's technical name, directory name, and Text Domain remain `event-card-manager` to prevent disruptions to upgrades from previous versions, settings, issued cards, and QR codes.

## Download the Latest Version

[![Download the Latest Version](https://img.shields.io/badge/⬇_Download_Latest_Version-event--card--manager.zip-3A70A7?style=for-the-badge)](https://github.com/maklf-dev/VibePassQR-wp/releases/latest/download/event-card-manager.zip)

- Releases page: [GitHub Releases](https://github.com/maklf-dev/VibePassQR-wp/releases)
- Current source version: `0.6.5`
- The installable file must also be uploaded to every Release under the consistent name `event-card-manager.zip` so the button above always downloads the latest version.

## Why VibePassQR?

This project has evolved in the spirit of Vibe Coding: real problems, real feedback, real testing, and continuous improvements. But "vibe" doesn't mean cutting corners here. Sensitive operations come with access controls, server-side validation, nonces, secure tokens, technical logging, and a backward-compatible upgrade path.

## Key Features

- Multi-event management with independent forms, text, attendance sessions, and settings
- Form builder supporting text, mobile numbers, email, options, HTML, and repeatable guest fields
- Iranian mobile number validation and normalization of Persian and Arabic numerals
- Free registration or offline financial recording, with an extensible integration point for payment gateways
- Separate cards and QR codes for the primary registrant and each guest
- Card recovery using a mobile number, tracking code, and optional SMS-based OTP
- Secure short links for cards (`/t/`) and attendance (`/a/`), using random tokens
- Individual or bulk card printing in a multipage document
- Automatic, operator-only, or hybrid attendance check-in modes
- Dedicated operator panel with camera scanning, image selection, and manual code entry
- Support for a primary registrant's QR code, with attendance selection for the registrant, all guests, or selected individuals
- Server-side prevention of duplicate attendance check-ins
- Logging of the operator, time, check-in method, and device information
- Separate event manager and attendance operator roles
- Automatic restoration of internal roles if they are deleted or their permissions become incomplete
- Dashboard and reports for people, events, operators, and attendance records
- CSV export, print views, and linked profiles
- Test registrations reserved for the main WordPress administrator, with filtering and selective deletion
- Exclusion of test registrations from operational reports and exports
- Repeated registration of mobile numbers whose previous records have been marked as test records
- SMS center, sending queue, attempt logs, and selectable transports
- Lightweight daily diagnostic reports for plugin operations
- Appearance settings for colors, typography, spacing, and custom CSS
- Persian-language, responsive interface suitable for mobile devices and reception desks

## Requirements

| Component | Minimum or Recommendation |
|---|---|
| WordPress | Version `6.5` or later |
| PHP | Version `8.0` or later, on 64-bit hosting |
| HTTPS | Required for browser camera access |
| OpenSSL | For secure tokens and encrypted data |
| ZipArchive | For certain exports and backup packages |
| Browser | An up-to-date version of Chrome, Edge, Firefox, or Safari |

## Installation

1. Back up your database and the `wp-content/uploads` directory.
2. Download `event-card-manager.zip` from the Releases section.
3. In WordPress, go to **Plugins → Add New Plugin → Upload Plugin**.
4. Install the ZIP file and activate the plugin.
5. Review and configure the event, form, attendance session, roles, and public pages.
6. Before using the plugin in a live event, test registration, recovery, OTP, and attendance check-in.

### Upgrading from a Previous Version

- Replace the existing version with the new ZIP package.
- Do not uninstall the previous plugin. A complete uninstall may delete plugin data according to its uninstall policy.
- After upgrading, open the dashboard and diagnostics page once to trigger and verify any required migrations.
- Cards, QR codes, registration IDs, and settings from previous versions are preserved during a normal upgrade.

Full guide: [Installation and Upgrading](docs/INSTALLATION-UPGRADE-FA.md)

## General Workflow

1. The main administrator creates an event and a registration form.
2. A user registers themselves and, if permitted, their guests.
3. The plugin generates an individual card, QR code, and tracking code for each person.
4. Cards are retrieved from the result page or through the secure recovery process.
5. An operator checks the QR code using the camera or a manually entered code.
6. Attendance is saved only after checking permissions, the event, the attendance session, and whether the person has already checked in.
7. Authorized managers view the reports and exports permitted by their access level.

## Roles and Permissions

| Role | Purpose |
|---|---|
| Main WordPress Administrator | Full settings, event management, reports, tools, test registrations, and selective deletion |
| Event Manager | View and manage authorized event sections and reports |
| Attendance Manager | Operational access to the reception panel and authorized attendance check-ins |

Event access can also be restricted to specific users in addition to role-based restrictions. Permissions are checked on the server, rather than relying solely on hiding buttons.

## Default Page Slugs

| Slug | Purpose |
|---|---|
| `event-register` | Event registration |
| `event-recovery` | Card recovery |
| `event-card` | Card display |
| `event-attendance` | Attendance check-in or confirmation |
| `event-reports` | Authorized reports |
| `event-panel` | Login and dedicated manager/operator panel |

Page names and creation can be managed or restored through the plugin's administration tools.

## Cards, QR Codes, and Recovery

- Each registrant and guest has a separate identity and card.
- A primary registrant's QR code can display the registrant and associated guests for group selection.
- A guest's QR code retrieves only that guest.
- Recovery uses a mobile number and, when necessary, a tracking code.
- Optional OTP codes are time-limited, single-use, and subject to attempt and resend limits.
- Raw OTP codes are never stored in reports, the SMS queue, or technical logs.

## Attendance Check-In

Operators can check QR codes using the browser camera, a saved image, or a manually entered code. The plugin accepts full links, QR codes, tokens, and 10-character ticket codes. Access controls, attendance-session time windows, and duplicate-check-in prevention are enforced on the server.

To use the camera:

- The website must use HTTPS.
- The browser must be granted camera permission.
- The required QR library is bundled with the plugin, so scanning does not depend on a CDN.

## Test Registrations

The actual main WordPress administrator can mark selected records as "test" records. A test record:

- Is excluded from other managers' operational reports and exports.
- Is exempt from duplicate mobile number restrictions, allowing test scenarios to be repeated.
- Remains usable for testing cards, OTP, SMS, and attendance.
- Can only be restored to normal status or permanently deleted by the main administrator.

> [!WARNING]
> Selective deletion is permanent and also removes data associated with the record. Back up your data first and verify the operation on a test record.

## SMS and OTP

The plugin core is designed to be independent of the messaging service. Available transports may vary depending on the installation environment. Regular notifications support queuing and retries; OTP messages are sent immediately because they are time-sensitive.

Before enabling live sending:

1. Complete the SMS service settings.
2. Run connection and credential tests.
3. Send a test message.
4. Test OTP using a phone number under your control.
5. Check the sending queue and delivery logs.

Never commit API keys, SMS panel passwords, or customer information to Git.

## Payments

No operational bank payment gateway is configured by default in this version. Registration without a gateway is saved with the `offline_confirmed` status; it does not falsely indicate that a bank payment succeeded. Developers can implement a payment gateway integration through the extensible `ecm_payment_result` integration point.

## Reports and Diagnostics

The plugin provides management reports, CSV exports, print views, and daily technical reports. Technical reporting is designed to help diagnose errors, response times, memory usage, query counts, OTP status, SMS status, and attendance operations.

Before posting a report in a public Issue:

- Remove or anonymize mobile numbers, names, email addresses, and registration data.
- Do not publish card tokens, QR codes, cookies, or login information.
- Remove API keys, SMS service passwords, and confidential hosting details.

## Project Structure

```text
VibePassQR-wp/
├── assets/
│   ├── css/                 # Public and admin styles
│   ├── js/                  # Public UI, QR, PDF, and operator panel
│   └── vendor/              # Required and permitted dependencies
├── docs/                    # Guides, tests, and release notes
├── includes/                # Core plugin classes and logic
├── languages/               # Translation files
├── templates/               # Display templates
├── event-card-manager.php   # Main plugin file
├── uninstall.php            # Complete uninstall policy
├── CHANGELOG.md             # Change history
├── THIRD-PARTY-NOTICES.md   # Third-party dependency licenses
└── README.md                # This file
```

> Plugin files must be placed directly in the repository root. The main file must be at `VibePassQR-wp/event-card-manager.php`, not `VibePassQR-wp/event-card-manager/event-card-manager.php`.

## Documentation

- [Installation and Upgrading Guide](docs/INSTALLATION-UPGRADE-FA.md)
- [Operations, Security, and Maintenance](docs/OPERATIONS-SECURITY-FA.md)
- [Acceptance Testing](docs/EMPLOYER-ACCEPTANCE-TEST-FA.md)
- [Release Notes for 0.6.5](docs/RELEASE-NOTES-0.6.5-FA.md)
- [QA Report for Version 0.6.5](docs/QA-REPORT-0.6.5-FA.md)
- [Complete Change History](CHANGELOG.md)

If you have separate HTML documentation, place it in this directory using a consistent name such as `docs/index.html`, and keep its version information aligned with the latest Release.

## Local Development

1. Clone the repository into `wp-content/plugins/`.
2. Make sure the active plugin directory is named `event-card-manager`, or link the project into your WordPress environment under that name.
3. Run compatible WordPress and PHP versions.
4. After code changes, test registration, recovery, OTP, cards, QR codes, and attendance scenarios.
5. Apply database changes only through additive modifications with a backward-compatible upgrade path.

At runtime, the project does not require a mandatory build process or a CDN for QR scanning.

## Security and Privacy

- Inputs must be validated and sanitized on the server.
- Outputs must be escaped according to their context in WordPress.
- Administrative operations must require valid capabilities and nonces.
- Security must not depend on keeping URLs or endpoint names secret.
- Secrets, `.env` files, `wp-config.php`, actual customer reports, databases, and backups must never be committed.
- Report security vulnerabilities privately first, and do not publicly disclose exploitable details before a fix is available.

For production deployments, backups, HTTPS, restricted administrative access, and testing new versions in a staging environment are strongly recommended.

## Current Limitations

- A real bank payment gateway requires separate configuration or development.
- SMS delivery depends on the selected service and the hosting environment's network conditions.
- Camera access requires HTTPS, a compatible browser, and user permission.
- High-traffic event performance must be load-tested against the resources of the actual hosting and database environment.
- This project does not replace your overall WordPress backup and security policies.

## Contributing

Issues and Pull Requests are welcome. Please:

1. Open an Issue before making major changes.
2. Do not attach sensitive information or real customer data.
3. Keep changes small, reviewable, and backward-compatible.
4. Explain how to test your changes and their impact on the database, roles, and upgrade path.
5. Check the Persian interface and RTL/LTR behavior on desktop and mobile.

## Proposed Roadmap

- Complete automated tests for critical workflows
- Add payment integration layers through independent adapters
- Further optimize reports for large datasets
- Expand English documentation and translation files
- Set up automated Release and quality assurance workflows with GitHub Actions

## License and Credits

This project is released under the **GPL-2.0-or-later** license. Third-party library licenses are listed in [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).

Built by [maklf-dev](https://github.com/maklf-dev), with code, coffee, testing, and a carefully measured dose of Vibe ☕💻

---

If VibePassQR helps shorten a long line at your event, give the repository a ⭐. Stars won't make queries run faster, but they definitely boost the developer's mood! 😄
