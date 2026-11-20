package br.com.deliveryzim.smsgateway

import android.Manifest
import android.app.Activity
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.telephony.SubscriptionManager
import android.widget.Button
import android.widget.EditText
import android.widget.TextView
import android.widget.Toast
import java.text.DateFormat
import java.util.Date

class GatewayActivity : Activity() {
    private lateinit var urlField: EditText
    private lateinit var tokenField: EditText
    private lateinit var phoneField: EditText
    private lateinit var statusField: TextView
    private val handler = Handler(Looper.getMainLooper())
    private var pendingAction = ""
    private val refresh = object : Runnable {
        override fun run() {
            updateStatus()
            handler.postDelayed(this, 3_000)
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)
        urlField = findViewById(R.id.apiUrl)
        tokenField = findViewById(R.id.apiToken)
        phoneField = findViewById(R.id.testPhone)
        statusField = findViewById(R.id.status)
        urlField.setText(GatewaySettings.url(this))
        tokenField.setText(GatewaySettings.token(this))

        findViewById<Button>(R.id.save).setOnClickListener { save() }
        findViewById<Button>(R.id.start).setOnClickListener {
            pendingAction = "start"
            requestPermissionOrExecute()
        }
        findViewById<Button>(R.id.stop).setOnClickListener {
            GatewaySettings.setEnabled(this, false)
            stopService(Intent(this, GatewayService::class.java))
            toast("Gateway parado")
            updateStatus()
        }
        findViewById<Button>(R.id.testSms).setOnClickListener {
            pendingAction = "test"
            requestPermissionOrExecute()
        }
    }

    private fun save(): Boolean {
        val address = urlField.text.toString().trim().trimEnd('/')
        val token = tokenField.text.toString().trim()
        val uri = try { java.net.URI(address) } catch (_: Exception) { null }
        if (uri?.scheme != "https" || uri?.host.isNullOrBlank() ||
            uri?.userInfo != null || uri?.query != null || uri?.fragment != null ||
            (uri?.path != null && uri.path != "" && uri.path != "/") || token.length < 20) {
            toast("Use URL HTTPS e token de no mínimo 20 caracteres")
            return false
        }
        return try {
            GatewaySettings.save(this, address, token)
            toast("Configuração salva com segurança")
            true
        } catch (_: Exception) {
            toast("Não foi possível proteger o token")
            false
        }
    }

    private fun requestPermissionOrExecute() {
        val required = mutableListOf(Manifest.permission.SEND_SMS)
        if (Build.VERSION.SDK_INT >= 33) required.add(Manifest.permission.POST_NOTIFICATIONS)
        val missing = required.filter { checkSelfPermission(it) != PackageManager.PERMISSION_GRANTED }
        if (missing.isNotEmpty()) {
            requestPermissions(missing.toTypedArray(), 71)
            return
        }
        executeAction()
    }

    override fun onRequestPermissionsResult(
        requestCode: Int, permissions: Array<out String>, grantResults: IntArray
    ) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        if (requestCode == 71) {
            if (checkSelfPermission(Manifest.permission.SEND_SMS) == PackageManager.PERMISSION_GRANTED) {
                executeAction()
            } else {
                toast("O Android não liberou SEND_SMS. Verifique o instalador e as permissões.")
            }
        }
    }

    private fun executeAction() {
        when (pendingAction) {
            "start" -> {
                if (!save()) return
                GatewaySettings.setEnabled(this, true)
                try {
                    startForegroundService(Intent(this, GatewayService::class.java))
                    toast("Gateway iniciado")
                } catch (_: Exception) {
                    GatewaySettings.setEnabled(this, false)
                    toast("Android impediu iniciar o serviço")
                }
            }
            "test" -> {
                val phone = phoneField.text.toString().filter { it.isDigit() }
                val normalized = if (phone.startsWith("55")) phone else "55" + phone
                if (!normalized.matches(Regex("^55[1-9][0-9][0-9]{8,9}$"))) {
                    toast("Informe um celular com DDD válido")
                    return
                }
                if (!GatewaySettings.useQuota(this)) {
                    toast("Limite preventivo de SMS atingido")
                    return
                }
                try {
                    SmsSender.send(this, normalized, "Deliveryzim: SMS de teste do gateway.", "test_" + System.currentTimeMillis())
                    toast("Envio solicitado à operadora; confirme o recebimento")
                } catch (_: Exception) {
                    toast("Não foi possível solicitar o envio")
                }
            }
        }
        pendingAction = ""
        updateStatus()
    }

    private fun updateStatus() {
        val poll = GatewaySettings.lastPoll(this)
        val last = if (poll == 0L) "ainda não conectado" else DateFormat.getTimeInstance(DateFormat.SHORT).format(Date(poll))
        val permission = checkSelfPermission(Manifest.permission.SEND_SMS) == PackageManager.PERMISSION_GRANTED
        val sim = SubscriptionManager.getDefaultSmsSubscriptionId()
        val error = GatewaySettings.lastError(this)
        statusField.text =
            (if (GatewaySettings.enabled(this)) "Gateway solicitado como ativo" else "Gateway parado") +
            "\nSMS autorizado: " + (if (permission) "sim" else "não") +
            "\nChip padrão: " + (if (sim == SubscriptionManager.INVALID_SUBSCRIPTION_ID) "não definido" else "configurado") +
            "\nÚltima consulta: " + last +
            "\nSMS enviados: " + GatewaySettings.sent(this) +
            (if (error.isEmpty()) "" else "\nAviso: " + error)
    }

    private fun toast(message: String) = Toast.makeText(this, message, Toast.LENGTH_LONG).show()

    override fun onResume() {
        super.onResume()
        handler.post(refresh)
    }
    override fun onPause() {
        handler.removeCallbacks(refresh)
        super.onPause()
    }
}
