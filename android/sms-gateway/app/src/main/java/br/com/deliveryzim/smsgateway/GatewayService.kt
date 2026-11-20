package br.com.deliveryzim.smsgateway

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.Service
import android.content.Intent
import android.content.pm.ServiceInfo
import android.os.IBinder
import org.json.JSONObject
import java.time.Instant
import java.util.concurrent.atomic.AtomicBoolean

/** Persistent, user-visible foreground service. Android may still stop it. */
class GatewayService : Service() {
    private val running = AtomicBoolean(false)
    private var worker: Thread? = null

    override fun onCreate() {
        super.onCreate()
        val manager = getSystemService(NotificationManager::class.java)
        manager.createNotificationChannel(
            NotificationChannel("gateway", "Gateway SMS ativo", NotificationManager.IMPORTANCE_LOW)
        )
        val notification = Notification.Builder(this, "gateway")
            .setContentTitle("Deliveryzim SMS Gateway")
            .setContentText("Aguardando solicitações de código da VPS")
            .setSmallIcon(android.R.drawable.stat_notify_chat)
            .setOngoing(true)
            .build()
        startForeground(101, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE)
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (!GatewaySettings.enabled(this)) {
            stopSelf()
            return START_NOT_STICKY
        }
        if (running.compareAndSet(false, true)) {
            worker = Thread {
                while (running.get()) {
                    try {
                        processJobs()
                    } catch (e: Exception) {
                        GatewaySettings.setError(this, e.message ?: "Falha de conexão")
                    }
                    try { Thread.sleep(15_000) } catch (_: InterruptedException) { break }
                }
            }.also { it.name = "deliveryzim-sms-poller"; it.isDaemon = true; it.start() }
        }
        return START_STICKY
    }

    private fun processJobs() {
        val jobs = GatewayApi(this).jobs()
        GatewaySettings.setPoll(this)
        for (i in 0 until jobs.length()) {
            val job = jobs.optJSONObject(i) ?: continue
            processOne(job)
        }
    }

    private fun processOne(job: JSONObject) {
        val id = job.optString("id")
        val phone = job.optString("phone")
        val message = job.optString("message")
        if (!id.matches(Regex("[A-Za-z0-9_-]{1,80}"))) return
        if (GatewaySettings.wasAttempted(this, id)) return

        val expiresAt = job.optString("expires_at")
        val notExpired = try {
            Instant.parse(expiresAt).toEpochMilli() > System.currentTimeMillis()
        } catch (_: Exception) { false }
        val api = GatewayApi(this)
        if (!notExpired || !SmsSender.acceptedOtp(phone, message)) {
            GatewaySettings.markAttempted(this, id)
            try { api.result(id, "rejected") } catch (_: Exception) {}
            return
        }
        if (!GatewaySettings.useQuota(this)) {
            GatewaySettings.setError(this, "Limite preventivo de SMS atingido")
            return
        }
        // Persist before attempting: never auto-repeat a potentially submitted SMS.
        GatewaySettings.markAttempted(this, id)
        try {
            SmsSender.send(this, phone, message, id)
        } catch (e: Exception) {
            GatewaySettings.setError(this, "Falha ao solicitar envio pelo chip")
            try { api.result(id, "failed") } catch (_: Exception) {}
        }
    }

    override fun onDestroy() {
        running.set(false)
        worker?.interrupt()
        super.onDestroy()
    }
    override fun onBind(intent: Intent?): IBinder? = null
}
