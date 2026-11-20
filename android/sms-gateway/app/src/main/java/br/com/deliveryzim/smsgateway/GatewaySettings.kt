package br.com.deliveryzim.smsgateway

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

object GatewaySettings {
    private const val PREF = "gateway_config"
    private const val KEY_ALIAS = "deliveryzim-sms-token-v1"

    private fun p(context: Context) = context.getSharedPreferences(PREF, Context.MODE_PRIVATE)

    fun url(context: Context): String = p(context).getString("url", "") ?: ""
    fun token(context: Context): String {
        val encoded = p(context).getString("token", "") ?: ""
        if (encoded.isEmpty()) return ""
        return try {
            val bytes = Base64.decode(encoded, Base64.NO_WRAP)
            val iv = bytes.copyOfRange(0, 12)
            val cipher = Cipher.getInstance("AES/GCM/NoPadding")
            cipher.init(Cipher.DECRYPT_MODE, key(), GCMParameterSpec(128, iv))
            String(cipher.doFinal(bytes.copyOfRange(12, bytes.size)), Charsets.UTF_8)
        } catch (_: Exception) { "" }
    }

    fun save(context: Context, url: String, token: String) {
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.ENCRYPT_MODE, key())
        val encrypted = cipher.iv + cipher.doFinal(token.toByteArray(Charsets.UTF_8))
        p(context).edit()
            .putString("url", url)
            .putString("token", Base64.encodeToString(encrypted, Base64.NO_WRAP))
            .apply()
    }

    private fun key(): SecretKey {
        val store = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        val existing = store.getKey(KEY_ALIAS, null)
        if (existing is SecretKey) return existing
        val generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore")
        generator.init(
            KeyGenParameterSpec.Builder(KEY_ALIAS,
                KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setKeySize(256)
                .build()
        )
        return generator.generateKey()
    }

    fun enabled(context: Context): Boolean = p(context).getBoolean("enabled", false)
    fun setEnabled(context: Context, value: Boolean) = p(context).edit().putBoolean("enabled", value).apply()
    fun lastPoll(context: Context): Long = p(context).getLong("last_poll", 0L)
    fun lastError(context: Context): String = p(context).getString("last_error", "") ?: ""
    fun sent(context: Context): Int = p(context).getInt("sent", 0)
    fun setPoll(context: Context) = p(context).edit().putLong("last_poll", System.currentTimeMillis()).putString("last_error", "").apply()
    fun setError(context: Context, reason: String) = p(context).edit().putString("last_error", reason.take(100)).apply()
    fun incrementSent(context: Context) = synchronized(this) {
        p(context).edit().putInt("sent", sent(context) + 1).apply()
    }

    // Persist the most recent job IDs locally. A job is never sent twice automatically.
    fun wasAttempted(context: Context, id: String): Boolean =
        (p(context).getString("attempted", "") ?: "").split('|').contains(id)

    fun markAttempted(context: Context, id: String) = synchronized(this) {
        val ids = (p(context).getString("attempted", "") ?: "")
            .split('|').filter { it.isNotEmpty() }.takeLast(499) + id
        p(context).edit().putString("attempted", ids.joinToString("|")).commit()
    }

    // Defensive device-side quota; the PHP server must have independent limits.
    fun useQuota(context: Context): Boolean = synchronized(this) {
        val now = System.currentTimeMillis()
        val pref = p(context)
        val hourStart = pref.getLong("hour_start", 0L)
        val dayStart = pref.getLong("day_start", 0L)
        val sameHour = now - hourStart in 0..3_599_999
        val sameDay = now - dayStart in 0..86_399_999
        val hourly = if (sameHour) pref.getInt("hourly", 0) else 0
        val daily = if (sameDay) pref.getInt("daily", 0) else 0
        if (hourly >= 10 || daily >= 50) return@synchronized false
        pref.edit()
            .putLong("hour_start", if (sameHour) hourStart else now)
            .putLong("day_start", if (sameDay) dayStart else now)
            .putInt("hourly", hourly + 1)
            .putInt("daily", daily + 1)
            .commit()
    }
}
