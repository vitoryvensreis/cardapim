package br.com.deliveryzim.smsgateway

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != Intent.ACTION_BOOT_COMPLETED || !GatewaySettings.enabled(context)) return
        if (GatewaySettings.url(context).isEmpty() || GatewaySettings.token(context).isEmpty()) return
        try {
            context.startForegroundService(Intent(context, GatewayService::class.java))
        } catch (_: Exception) {
            GatewaySettings.setError(context, "Abra o aplicativo após reiniciar para ativar o gateway")
        }
    }
}
