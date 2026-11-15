# Security Policy

This repository contains code intended to run inside a DataLife Engine (DLE) installation and connect to a PeeStream account.

## Release status

The v1.0.4-beta.1 candidate is **beta software**. **Live DLE end-to-end testing has not been performed.** Install only in an isolated staging environment, with a complete backup of the DLE database and files.

## Private vulnerability reports

Never post API keys, webhook secrets, cron secrets, server addresses, database credentials, or exploit details in public Issues.

Use the repository's **Security → Report a vulnerability** option if private vulnerability reporting is enabled. If it is not available, request a private security contact method through the [official PeeStream website](https://peestream.com) rather than publishing sensitive information.

Share the addon version, DLE/PHP/database versions, a minimal reproduction, impact, and sanitized logs.

## Safe configuration

Use separate test credentials and staging data. Do not install a beta on a production site. Verify permissions for the generated configuration file and restrict access to secrets.
