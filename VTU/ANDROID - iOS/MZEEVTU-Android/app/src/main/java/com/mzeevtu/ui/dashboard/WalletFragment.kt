package com.mzeevtu.ui.dashboard

import android.os.Bundle
import android.view.View
import android.widget.LinearLayout
import androidx.fragment.app.Fragment
import androidx.navigation.fragment.findNavController
import com.mzeevtu.R
import com.mzeevtu.util.safeNavigate

class WalletFragment : Fragment(R.layout.fragment_wallet) {

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        val nav = findNavController()

        // Each entire card + its action button both navigate to the service
        val goVcard: () -> Unit = { nav.safeNavigate(R.id.nav_vcard) }
        val goGiftcard: () -> Unit = { nav.safeNavigate(R.id.nav_giftcard) }
        val goCrypto: () -> Unit = { nav.safeNavigate(R.id.nav_crypto) }

        view.findViewById<LinearLayout>(R.id.card_virtual_card)?.setOnClickListener { goVcard() }
        view.findViewById<LinearLayout>(R.id.btn_open_vcard)?.setOnClickListener { goVcard() }

        view.findViewById<LinearLayout>(R.id.card_gift_card)?.setOnClickListener { goGiftcard() }
        view.findViewById<LinearLayout>(R.id.btn_open_giftcard)?.setOnClickListener { goGiftcard() }

        view.findViewById<LinearLayout>(R.id.card_crypto)?.setOnClickListener { goCrypto() }
        view.findViewById<LinearLayout>(R.id.btn_open_crypto)?.setOnClickListener { goCrypto() }
    }
}
