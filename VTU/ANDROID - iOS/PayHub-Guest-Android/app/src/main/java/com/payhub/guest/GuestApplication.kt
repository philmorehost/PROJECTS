package com.payhub.guest

import android.app.Application
import com.payhub.guest.util.CrashHandler

class GuestApplication : Application() {
    override fun onCreate() {
        super.onCreate()
        CrashHandler.install(this)
    }
}
