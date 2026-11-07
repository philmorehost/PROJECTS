# DGV7 Official WhatsApp Integration Guide (Meta Cloud API)

This guide explains how to set up the **Official WhatsApp Cloud API** for your DGV7 platform. This strategy replaces the old "linked device" bridge with a professional, high-performance solution directly from Meta.

## 1. Meta Developer Portal Setup

1.  **Create a Meta Developer App**:
    *   Go to [Meta for Developers](https://developers.facebook.com/).
    *   Create a new App (Select "Other" -> "Business").
2.  **Add WhatsApp to your App**:
    *   In the App Dashboard, find "WhatsApp" and click **Set up**.
3.  **Get Configuration Details**:
    *   In the sidebar, go to **WhatsApp** -> **API Setup**.
    *   You will see your **Phone Number ID** and **WhatsApp Business Account ID**.
4.  **Generate Permanent Access Token**:
    *   **Important**: The "Temporary Access Token" expires in 24 hours.
    *   Go to **Business Settings** -> **Users** -> **System Users**.
    *   Add a new System User (Role: Admin).
    *   Click **Generate New Token**. Select your App and the following permissions:
        *   `whatsapp_business_messaging`
        *   `whatsapp_business_management`
    *   **Copy and save this token**; it will never expire.

## 2. System Configuration (Super Admin)

1.  Log in to your **Super Admin Dashboard**.
2.  Navigate to **WhatsApp AI Manager**.
3.  Enter your:
    *   **Permanent Access Token**
    *   **Phone Number ID**
    *   **Business Account ID**
4.  Click **Save Official Settings**.

## 3. Vendor Activation

1.  Each Vendor can now go to their **WhatsApp Notification Center**.
2.  If the Super Admin has configured the API, they will see the **"Official API Ready"** status.
3.  Vendors simply click **"Activate WhatsApp Alerts"** to start receiving real-time notifications for transactions.

## 4. Broadcasting (Up to 200 Users)

The Official API is built for scale. 
*   Vendors can select up to **200 registered users** from their dashboard.
*   The system dispatches messages via Meta's infrastructure, ensuring near-instant delivery.
*   **Best Practice**: Ensure your message content complies with Meta's Business Policies to avoid being flagged.

## 5. Troubleshooting

*   **"Failed to send"**: Verify your Permanent Token hasn't been revoked in the Meta Business Suite.
*   **Users not showing**: Ensure users have a phone number registered in their profile. The broadcast tool only displays users with valid numeric entries.
*   **Normalization**: The system automatically converts Nigerian `080...` numbers to the required `23480...` format.

---
*DGV7 — Empowering your business with professional-grade automation.*
