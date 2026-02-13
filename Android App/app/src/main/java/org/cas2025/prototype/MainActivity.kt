package org.cas2025.prototype

import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.content.SharedPreferences
import android.os.Build
import android.os.Bundle
import android.widget.Toast
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import org.cas2025.prototype.databinding.ActivityMainBinding
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL

class MainActivity : AppCompatActivity() {

    private lateinit var binding: ActivityMainBinding
    private lateinit var prefs: SharedPreferences

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)

        prefs = getSharedPreferences(PREFS_NAME, MODE_PRIVATE)

        binding.serverUrlInput.setText(prefs.getString(KEY_SERVER_URL, DEFAULT_SERVER_URL))

        binding.startButton.setOnClickListener {
            val serverUrl = binding.serverUrlInput.text.toString().trim().trimEnd('/')
            if (!isValidServerUrl(serverUrl)) {
                Toast.makeText(this, R.string.error_server_required, Toast.LENGTH_LONG).show()
                return@setOnClickListener
            }

            prefs.edit()
                .putString(KEY_SERVER_URL, serverUrl)
                .apply()

            val portalUrl = buildPortalUrl(serverUrl)
            startActivity(Intent(this, WebViewActivity::class.java).putExtra(EXTRA_PORTAL_URL, portalUrl))
        }

        binding.checkUpdatesButton.setOnClickListener {
            val serverUrl = binding.serverUrlInput.text.toString().trim().trimEnd('/')
            if (!isValidServerUrl(serverUrl)) {
                Toast.makeText(this, R.string.error_server_required, Toast.LENGTH_LONG).show()
                return@setOnClickListener
            }
            checkForUpdates(serverUrl)
        }
    }

    private fun isValidServerUrl(url: String): Boolean {
        return url.startsWith("https://") || url.startsWith("http://")
    }

    private fun buildPortalUrl(serverUrl: String): String {
        val connector = if (serverUrl.contains('?')) "&" else "?"
        return "$serverUrl${connector}mobile=1"
    }

    private fun checkForUpdates(serverUrl: String) {
        binding.checkUpdatesButton.isEnabled = false
        Toast.makeText(this, R.string.update_checking, Toast.LENGTH_SHORT).show()

        Thread {
            try {
                val manifestUrl = "$serverUrl/$UPDATE_MANIFEST_PATH"
                val conn = (URL(manifestUrl).openConnection() as HttpURLConnection).apply {
                    connectTimeout = 8000
                    readTimeout = 8000
                    requestMethod = "GET"
                }

                val statusCode = conn.responseCode
                if (statusCode !in 200..299) {
                    throw IllegalStateException("HTTP $statusCode")
                }

                val body = conn.inputStream.bufferedReader().use { it.readText() }
                val json = JSONObject(body)
                val installedVersionCode = getInstalledVersionCode()
                val latestVersionCode = json.optInt("latestVersionCode", installedVersionCode)
                val apkUrl = json.optString("apkUrl", "")
                val notes = json.optString("notes", "")

                runOnUiThread {
                    binding.checkUpdatesButton.isEnabled = true
                    if (latestVersionCode > installedVersionCode && apkUrl.isNotBlank()) {
                        showUpdateDialog(latestVersionCode, apkUrl, notes)
                    } else {
                        Toast.makeText(this, R.string.update_up_to_date, Toast.LENGTH_LONG).show()
                    }
                }
            } catch (_: Exception) {
                runOnUiThread {
                    binding.checkUpdatesButton.isEnabled = true
                    Toast.makeText(this, R.string.update_check_failed, Toast.LENGTH_LONG).show()
                }
            }
        }.start()
    }


    private fun getInstalledVersionCode(): Int {
        val packageInfo = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            packageManager.getPackageInfo(packageName, PackageManager.PackageInfoFlags.of(0))
        } else {
            @Suppress("DEPRECATION")
            packageManager.getPackageInfo(packageName, 0)
        }

        val versionCode = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            packageInfo.longVersionCode
        } else {
            @Suppress("DEPRECATION")
            packageInfo.versionCode.toLong()
        }

        return versionCode.coerceAtMost(Int.MAX_VALUE.toLong()).toInt()
    }

    private fun showUpdateDialog(latestVersionCode: Int, apkUrl: String, notes: String) {
        val message = buildString {
            append(getString(R.string.update_available_message, latestVersionCode))
            if (notes.isNotBlank()) {
                append("\n\n")
                append(notes)
            }
        }

        AlertDialog.Builder(this)
            .setTitle(R.string.update_available_title)
            .setMessage(message)
            .setNegativeButton(android.R.string.cancel, null)
            .setPositiveButton(R.string.update_download_now) { _, _ ->
                startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(apkUrl)))
            }
            .show()
    }

    companion object {
        const val EXTRA_PORTAL_URL = "extra_portal_url"
        private const val PREFS_NAME = "cas_mobile_prefs"
        private const val KEY_SERVER_URL = "server_url"
        private const val DEFAULT_SERVER_URL = "https://cas.example.org"
        private const val UPDATE_MANIFEST_PATH = "mobile-app-update.json"
    }
}
