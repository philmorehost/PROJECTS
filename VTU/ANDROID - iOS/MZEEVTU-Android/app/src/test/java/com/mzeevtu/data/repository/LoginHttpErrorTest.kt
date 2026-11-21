package com.mzeevtu.data.repository

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Test

class LoginHttpErrorTest {
    @Test fun businessPolicyReasonIsNotReplacedWithBare403() {
        assertEquals("Business accounts are accessible via web only", LoginHttpError.message(403,
            """{"status":"error","code":"BUSINESS_WEB_ONLY","message":"Business accounts are accessible via web only"}"""))
    }
    @Test fun outageDescriptionIsRetained() {
        assertEquals("Platform temporarily suspended", LoginHttpError.message(503,
            """{"status":"failed","desc":"Platform temporarily suspended"}"""))
    }
    @Test fun infrastructureHtmlIsNotShown() {
        assertEquals("Sign-in was denied (HTTP 403). Please contact support.",
            LoginHttpError.message(403, "<html>Forbidden</html>"))
        assertFalse(LoginHttpError.message(403, """{"message":"<script>private detail</script>"}""").contains("<"))
    }
    @Test fun missingOrMalformedBodyHasUsefulFallback() {
        assertEquals("Sign-in could not be verified. Please sign in again.", LoginHttpError.message(401, null))
        assertEquals("Too many sign-in attempts. Please wait and try again.", LoginHttpError.message(429, "invalid"))
        assertEquals("Sign-in was denied (HTTP 403). Please contact support.", LoginHttpError.message(403, """{"message":403}"""))
    }
}
