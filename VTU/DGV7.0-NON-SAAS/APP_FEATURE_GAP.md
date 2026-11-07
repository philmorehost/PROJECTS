# Feature Gap Analysis: Website vs. Mobile Apps

This document outlines the features currently available on the VTU website platform that are missing from the mobile applications (DG6-Android, DG6-iOS, MZEEVTU, and MZEEVTU-iOS).

## Missing Features in Mobile Apps

1.  **AI Assistant & AI Purchase Feature**:
    - The website features a comprehensive AI Assistant (`jsfile/ai-assistant.js`) capable of processing VTU transactions (Airtime, Data, Utility, etc.) through natural language prompts.
    - **Status**: This feature is **NOT** yet implemented in the Android or iOS source code.
    - **Customer Impact**: Customers **cannot** currently use AI to make purchases within the mobile apps.

2.  **AI Budgeting & Financial Analytics**:
    - The website includes `ai-budgeting.php` which provides AI-driven financial insights. This is currently absent from the mobile apps.

3.  **AI Knowledge Base/Guide**:
    - The `ai-guide-cache.php` and related AI guidance features on the web are not integrated into the mobile experience.

4.  **Advanced Transaction Filtering**:
    - While the apps have transaction history, the website offers more granular filtering and export options (e.g., `export-transactions.php`) that are not fully mirrored in the app UI.

## Technical Conclusion on AI Status

After a thorough review of the `DG6-Android`, `DG6-iOS`, `MZEEVTU`, and `MZEEVTU-iOS` source code, it is confirmed that:
- No AI-related fragments, viewmodels, or services exist in the native codebases.
- The `nav_graph.xml` for Android apps does not contain any destinations for AI services.
- There is no logic for interfacing with the `ai-handler.php` endpoint from the mobile apps.

**Recommendation**: To enable AI in the apps, a new module must be developed for both Kotlin (Android) and Swift (iOS) to interface with the existing `web/ai-handler.php` API.
