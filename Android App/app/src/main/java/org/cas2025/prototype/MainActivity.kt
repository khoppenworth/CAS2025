package org.cas2025.prototype

import android.content.Intent
import android.content.SharedPreferences
import android.os.Bundle
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import org.cas2025.prototype.databinding.ActivityMainBinding

class MainActivity : AppCompatActivity() {

    private lateinit var binding: ActivityMainBinding
    private lateinit var prefs: SharedPreferences

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)

        prefs = getSharedPreferences(PREFS_NAME, MODE_PRIVATE)

        binding.serverUrlInput.setText(prefs.getString(KEY_SERVER_URL, DEFAULT_SERVER_URL))
        binding.userCodeInput.setText(prefs.getString(KEY_USER_CODE, ""))

        binding.startButton.setOnClickListener {
            val serverUrl = binding.serverUrlInput.text.toString().trim().trimEnd('/')
            val userCode = binding.userCodeInput.text.toString().trim()

            if (!isValidServerUrl(serverUrl)) {
                Toast.makeText(this, R.string.error_server_required, Toast.LENGTH_LONG).show()
                return@setOnClickListener
            }

            prefs.edit()
                .putString(KEY_SERVER_URL, serverUrl)
                .putString(KEY_USER_CODE, userCode)
                .apply()

            val portalUrl = buildPortalUrl(serverUrl, userCode)
            startActivity(Intent(this, WebViewActivity::class.java).putExtra(EXTRA_PORTAL_URL, portalUrl))
        }
    }

    private fun isValidServerUrl(url: String): Boolean {
        return url.startsWith("https://") || url.startsWith("http://")
    }

    private fun buildPortalUrl(serverUrl: String, userCode: String): String {
        val encodedCode = java.net.URLEncoder.encode(userCode, Charsets.UTF_8.name())
        val connector = if (serverUrl.contains('?')) "&" else "?"
        return "$serverUrl${connector}mobile=1&userCode=$encodedCode"
    }

    companion object {
        const val EXTRA_PORTAL_URL = "extra_portal_url"
        private const val PREFS_NAME = "cas_mobile_prefs"
        private const val KEY_SERVER_URL = "server_url"
        private const val KEY_USER_CODE = "user_code"
        private const val DEFAULT_SERVER_URL = "https://cas.example.org"
    }
}
