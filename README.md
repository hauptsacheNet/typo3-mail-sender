# TYPO3 Extension: Mail Sender Configuration

> 🚧 **Work in Progress**

Configure and validate email sender addresses with DNS and deliverability checks for TYPO3 CMS.

## About This Project

This extension is proudly funded by the **TYPO3 Community Budget** for Q4 2025. It was selected by TYPO3 members as one of four ideas to receive community funding, reflecting the strong need for better email sender configuration and validation in TYPO3.

**Learn more:** [TYPO3 Community Budget Q4 2025 Winners](https://typo3.org/article/members-have-selected-four-ideas-to-be-funded-in-quarter-4-2025)

## Current Features

### Phase 1: Foundation ✅
- ✅ Manage email sender addresses in the TYPO3 backend
- ✅ Store sender email addresses and display names
- ✅ Database structure for validation tracking
- ✅ System record configuration (root-level records)
- ✅ Integration in TYPO3's System Information/Status module
- ✅ Custom TCA renderType for validation status display

### Phase 2: Core Validation 🔄 (In Progress)
- ✅ Email syntax validation
- ✅ MX record verification
- ✅ SPF record validation (checks SMTP transport configuration)
- ✅ DMARC record analysis with recommendations
- ✅ Email existence verification (SMTP check)
- ✅ Validation result caching and display
- ✅ CLI command for validation (`mail:sender:validate`)
- 🔄 Tweaking email existence check failure handling

### Phase 3: Integration & Adoption 🔄 (In Progress)
- ✅ Integration with ext:form (validated sender dropdown)
- 📋 TCA Extra Field API for third-party extensions
- 📋 Documentation for extension developers

## Planned Features

- 📋 **Phase 4:** Import from existing configurations

## Roadmap

### Phase 1: Foundation ✅ Complete

**Goal:** Basic extension structure and system integration

**Features:**
- Extension scaffolding (composer.json, ext_emconf.php, TCA)
- Database schema for sender address configuration
- TCA configuration for backend management
- Basic CRUD operations with functional tests
- System record design (root-level, no translation)
- Integration in TYPO3's System Information/Status module
- Custom TCA renderType for validation status display

**Deliverable:** Functional extension with sender address management in TYPO3 backend

---

### Phase 2: Core Validation 🔄 In Progress

**Goal:** Implement email validation - the core functionality

**Features:**
- ✅ Email syntax validation
- ✅ SPF record validation using `dns_get_record()`
- ✅ MX record verification
- ✅ DMARC record analysis with recommendations
- ✅ Email existence checks via SMTP verification
- ✅ Validation result caching for performance
- ✅ Status reporting and visual display
- ✅ CLI command for validation
- 🔄 Refinement of email existence check error handling

**Deliverable:** Complete email validation system with DNS checks and status reporting

---

### Phase 3: Integration & Adoption 🔄 In Progress

**Goal:** Wide adoption through easy integration

**Features:**
- ✅ Integration with ext:form (replace freetext sender with validated dropdown)
- 📋 TCA Extra Field API for third-party extensions (similar to `enableRichtext`)
- 📋 Identify and integrate with popular TYPO3 extensions
- 📋 Comprehensive documentation for extension developers
- 📋 Integration testing and examples

**Deliverable:** API and integrations enabling easy adoption by extension developers

---

### Phase 4: Polish & Import 📋 Planned

**Goal:** Production-ready release with import functionality
**Target:** December 2025

**Features:**
- Import functionality:
  - From `$GLOBALS['TYPO3_CONF_VARS']['MAIL']`
  - From TypoScript configurations
  - From other extension databases
- Reference tracking (show where sender addresses are used)
- Final documentation and changelog
- Testing, bug fixes, and polish
- TER (TYPO3 Extension Repository) release preparation
- TYPO3.org news article

**Deliverable:** Release-ready extension with full documentation

## Requirements

- TYPO3 13.4 or later
- PHP 8.1 or later

## Installation

Install via Composer:

```bash
composer require hn/typo3-mail-sender
```

## Usage

After installation, mail sender addresses can be managed in the TYPO3 backend:

1. Navigate to the **List** module
2. Select the **root page** (ID: 0)
3. Create new **"Mail Sender Address"** records

### Record Fields

Each sender address record contains:

- **Sender Email Address**: The email address to be used as sender
- **Sender Name**: Display name for the sender
- **Hidden**: Toggle to temporarily disable a sender address

#### Validation Fields

The following fields are populated automatically by validation services:

- **Validation Status**: Current validation state (pending, valid, invalid)
- **Last Validation Check**: Timestamp of the last validation
- **Validation Result**: Detailed JSON validation results

## Development

### Running Tests

```bash
composer test
```

The extension uses the TYPO3 testing framework with SQLite. Tests are automatically bootstrapped via the `Build/setup-typo3.sh` script.

### Test Coverage

Current functional tests cover:
- ✅ Basic CRUD operations
- ✅ Record soft-delete
- ✅ Record hiding/visibility
- ✅ TCA configuration
- ✅ Database schema

## Contributing

We welcome contributions from the TYPO3 community! As a community-funded project, your input helps shape this extension.

**Ways to contribute:**
- 💡 Share feedback and feature suggestions via GitHub Issues
- 🐛 Report bugs and issues
- 📖 Improve documentation
- 🧪 Help with testing
- 💻 Submit pull requests

Please check the [GitHub repository](https://github.com/hauptsacheNet/typo3-mail-sender) for open issues and contribution guidelines.

## Project Status

**Current Phase:** Phase 2 (Core Validation) & Phase 3 (Integration)
**Next Milestone:** Phase 4 (Polish & Import)
**Target Release:** December 2025

## License

GPL-2.0-or-later

## Authors

- **Marco Pfeiffer** - marco@hauptsache.net
- **TYPO3 Community** - Funded by Community Budget Q4 2025

## Acknowledgments

Special thanks to the TYPO3 community for selecting this project for funding and supporting open-source development in the TYPO3 ecosystem.
