package br.com.deliveryzim.smsgateway

import android.app.Activity
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

class SmsResultReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val job = intent.getStringExtra("job") ?: return
        val event = intent.action ?: return
        val status = when (event) {
            "br.com.deliveryzim.smsgateway.SENT" ->
                if (resultCode == Activity.RESULT_OK) "sent" else "failed"
            "br.com.deliveryzim.smsgateway.DELIVERED" ->
                if (resultCode == Activity.RESULT_OK) "delivered" else "delivery_unknown"
            else -> return
        }
        if (status == "sent") GatewaySettings.incrementSent(context)
        if (status == "failed") GatewaySettings.setError(context, "Operadora recusou o envio")
        if (!job.startsWith("test_")) {
            Thread {
                try {
                    GatewayApi(context.applicationContext).result(job, status)
                } catch (_: Exception) {
                    GatewaySettings.setError(context, "Não foi possível confirmar resultado na API")
                }
            }.start()
        }
    }
}
