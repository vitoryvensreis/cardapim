package br.com.deliveryzim.smsgateway

import android.content.Context
import org.json.JSONArray
import org.json.JSONObject
import java.io.IOException
import java.net.HttpURLConnection
import java.net.URL

/** Private API contract. Server routes are not yet deployed in the PHP application. */
class GatewayApi(private val context: Context) {
    private fun call(method: String, path: String, body: JSONObject? = null): JSONObject {
        val base = GatewaySettings.url(context).trimEnd('/')
        val token = GatewaySettings.token(context)
        if (!base.startsWith("https://") || token.isEmpty()) throw IOException("Configure URL HTTPS e token")
        val connection = URL(base + path).openConnection() as HttpURLConnection
        connection.connectTimeout = 8_000
        connection.readTimeout = 8_000
        connection.instanceFollowRedirects = false
        connection.requestMethod = method
        connection.setRequestProperty("Authorization", "Bearer " + token)
        connection.setRequestProperty("Accept", "application/json")
        connection.setRequestProperty("Cache-Control", "no-store")
        if (body != null) {
            connection.doOutput = true
            connection.setRequestProperty("Content-Type", "application/json")
            connection.outputStream.use { it.write(body.toString().toByteArray(Charsets.UTF_8)) }
        }
        try {
            val status = connection.responseCode
            if (status !in 200..299) throw IOException("API HTTP " + status)
            val payload = connection.inputStream.bufferedReader().use { it.readText() }
            if (payload.length > 16_384) throw IOException("Resposta muito grande")
            return JSONObject(payload)
        } finally {
            connection.disconnect()
        }
    }

    fun jobs(): JSONArray = call("GET", "/api/v1/sms-gateway/jobs?limit=5")
        .optJSONArray("jobs") ?: JSONArray()

    fun result(jobId: String, status: String) {
        if (!jobId.matches(Regex("[A-Za-z0-9_-]{1,80}"))) return
        call("POST", "/api/v1/sms-gateway/jobs/" + jobId + "/result",
            JSONObject().put("status", status))
    }
}
