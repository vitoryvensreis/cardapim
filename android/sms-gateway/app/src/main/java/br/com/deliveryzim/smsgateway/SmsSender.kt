package br.com.deliveryzim.smsgateway

import android.Manifest
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.telephony.SmsManager
import android.telephony.SubscriptionManager

object SmsSender {
    private val phonePattern = Regex("^55[1-9][0-9][0-9]{8,9}$")
    private val otpPattern = Regex("\\b[0-9]{6}\\b")

    fun acceptedOtp(phone: String, message: String): Boolean =
        phonePattern.matches(phone) && message.length in 12..160 &&
        message.startsWith("Deliveryzim:") && otpPattern.containsMatchIn(message)

    fun send(context: Context, phone: String, message: String, id: String) {
        if (context.checkSelfPermission(Manifest.permission.SEND_SMS) != PackageManager.PERMISSION_GRANTED) {
            throw SecurityException("Permissão de SMS não concedida")
        }
        if (!phonePattern.matches(phone)) throw IllegalArgumentException("Número brasileiro inválido")
        if (message.isEmpty() || message.length > 160) throw IllegalArgumentException("Mensagem inválida")
        val sent = Intent(context, SmsResultReceiver::class.java).apply {
            action = "br.com.deliveryzim.smsgateway.SENT"
            putExtra("job", id)
        }
        val delivered = Intent(context, SmsResultReceiver::class.java).apply {
            action = "br.com.deliveryzim.smsgateway.DELIVERED"
            putExtra("job", id)
        }
        val flags = PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        val sentPi = PendingIntent.getBroadcast(context, id.hashCode(), sent, flags)
        val deliveredPi = PendingIntent.getBroadcast(context, id.hashCode() xor 0x4a6f72, delivered, flags)
        val subscriptionId = SubscriptionManager.getDefaultSmsSubscriptionId()
        @Suppress("DEPRECATION")
        val manager = if (subscriptionId != SubscriptionManager.INVALID_SUBSCRIPTION_ID) {
            SmsManager.getSmsManagerForSubscriptionId(subscriptionId)
        } else {
            SmsManager.getDefault()
        }
        manager.sendTextMessage(phone, null, message, sentPi, deliveredPi)
    }
}
