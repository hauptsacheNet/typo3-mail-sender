.. include:: Includes.txt

==========================
Mail Sender Configuration
==========================

:Extension key:
   mail_sender

:Version:
   |release|

:Language:
   en

:Author:
   Marco Pfeiffer

:Email:
   marco@hauptsache.net

:License:
   This extension is published under the
   `GNU General Public License v2.0 <https://www.gnu.org/licenses/old-licenses/gpl-2.0.html>`__

Configure and validate email sender addresses with DNS and deliverability checks for TYPO3 CMS.

**Table of Contents**

.. toctree::
   :maxdepth: 2

   Introduction/Index
   Installation/Index
   Usage/Index

.. _introduction:

Introduction
============

What does it do?
----------------

This extension provides a system record type for managing email sender addresses in TYPO3.
It includes validation capabilities for DNS records (SPF, DMARC, MX) and email deliverability checks.

Features
--------

* Manage email sender addresses in the TYPO3 backend
* Store sender email addresses and display names
* Validation status tracking
* DNS record validation (Phase 2)
* Email deliverability checks (Phase 2)

.. _installation:

Installation
============

Install the extension via Composer:

.. code-block:: bash

   composer require hn/mail-sender

.. _usage:

Usage
=====

Managing Sender Addresses
--------------------------

After installation, mail sender addresses can be managed in the TYPO3 backend:

1. Navigate to the List module
2. Select the root page (ID: 0)
3. Create new "Mail Sender Address" records

Configuration Fields
--------------------

Each sender address record contains:

* **Sender Email Address**: The email address to be used as sender
* **Sender Name**: Display name for the sender
* **Validation Status**: Current validation state (pending, valid, invalid)
* **Last Validation Check**: Timestamp of the last validation
* **Validation Result**: Detailed JSON validation results
