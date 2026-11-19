package br.com.deliveryzim.virtualprinter;

import android.Manifest;
import android.app.Activity;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.graphics.Color;
import android.graphics.Typeface;
import android.graphics.drawable.GradientDrawable;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.view.View;
import android.widget.Button;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;
import android.widget.Toast;
import org.json.JSONArray;
import org.json.JSONObject;
import java.net.Inet4Address;
import java.net.NetworkInterface;
import java.net.Socket;
import java.io.OutputStream;
import java.util.Collections;
import java.util.Enumeration;

public final class MainActivity extends Activity {
    private static final int WINE = Color.rgb(101, 20, 36);
    private static final int ORANGE = Color.rgb(255, 88, 37);
    private static final int INK = Color.rgb(34, 34, 34);
    private static final int MUTED = Color.rgb(100, 100, 100);
    private TextView status, addresses, headline, simulation;
    private LinearLayout jobs;
    private final Handler handler = new Handler(Looper.getMainLooper());
    private final Runnable timer = new Runnable() {
        @Override public void run() {
            refresh();
            handler.postDelayed(this, 1800);
        }
    };

    @Override public void onCreate(Bundle state) {
        super.onCreate(state);
        getWindow().setStatusBarColor(WINE);
        getWindow().setNavigationBarColor(Color.WHITE);
        ScrollView scroll = new ScrollView(this);
        scroll.setFillViewport(true);
        scroll.setBackgroundColor(Color.WHITE);
        LinearLayout root = new LinearLayout(this);
        root.setPadding(dp(20), dp(22), dp(20), dp(22));
        root.setOrientation(LinearLayout.VERTICAL);
        scroll.addView(root);
        setContentView(scroll);

        headline = label("Deliveryzim", 30, WINE, true);
        root.addView(headline);
        root.addView(label("Impressora virtual ESC/POS", 16, MUTED, false));
        root.addView(gap(20));

        status = label("Inicializando...", 16, WINE, true);
        root.addView(status);
        root.addView(gap(8));

        addresses = label("Procurando enderecos de rede...", 14, INK, false);
        addresses.setTextIsSelectable(true);
        root.addView(addresses);
        root.addView(gap(16));

        Button start = button("Iniciar impressora", WINE);
        start.setOnClickListener(v -> {
            if (Build.VERSION.SDK_INT >= 33 && checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
                requestPermissions(new String[] { Manifest.permission.POST_NOTIFICATIONS }, 20);
            }
            startForegroundService(new Intent(this, PrinterService.class));
            Toast.makeText(this, "Iniciando servidor TCP...", Toast.LENGTH_SHORT).show();
            handler.postDelayed(this::refresh, 450);
        });
        root.addView(start);

        Button stop = button("Parar impressora", INK);
        stop.setOnClickListener(v -> {
            startService(new Intent(this, PrinterService.class).setAction(PrinterService.STOP));
            handler.postDelayed(this::refresh, 400);
        });
        root.addView(stop);

        Button test = button("Enviar cupom de teste", ORANGE);
        test.setOnClickListener(v -> new Thread(() -> {
            try (Socket socket = new Socket("127.0.0.1", PrinterService.PORT)) {
                OutputStream out = socket.getOutputStream();
                out.write(new byte[] { 0x1b, '@' });
                out.write("DELIVERYZIM - CUPOM DE TESTE\n2x X-Bacon\n1x Refrigerante\nTOTAL R$ 50,00\n\n".getBytes("IBM850"));
                out.write(new byte[] { 0x1d, 'V', 0 });
                out.flush();
                runOnUiThread(() -> Toast.makeText(this, "Cupom enviado", Toast.LENGTH_SHORT).show());
            } catch (Exception e) {
                runOnUiThread(() -> Toast.makeText(this, "Inicie a impressora primeiro", Toast.LENGTH_SHORT).show());
            }
        }, "deliveryzim-selftest").start());
        root.addView(test);
        root.addView(gap(12));

        root.addView(label("Simular estado", 18, WINE, true));
        simulation = label("", 14, MUTED, false);
        root.addView(simulation);
        Button mode = button("Alternar estado simulado", INK);
        mode.setOnClickListener(v -> {
            int next = (getSharedPreferences("device", MODE_PRIVATE).getInt("mode", 0) + 1) % 4;
            getSharedPreferences("device", MODE_PRIVATE).edit().putInt("mode", next).apply();
            if (PrinterService.running) startService(new Intent(this, PrinterService.class).setAction(PrinterService.MODE).putExtra("mode", next));
            refresh();
        });
        root.addView(mode);
        root.addView(gap(18));

        root.addView(label("Cupons recebidos", 20, WINE, true));
        root.addView(label("Historico local e comandos capturados", 13, MUTED, false));
        jobs = new LinearLayout(this);
        jobs.setOrientation(LinearLayout.VERTICAL);
        root.addView(jobs);
        root.addView(gap(20));
        root.addView(label("Somente rede local. Nao exponha a porta 9100 na internet.", 12, MUTED, false));
        refresh();
    }

    private void refresh() {
        if (status == null) return;
        if (PrinterService.running) status.setText("ATIVA  |  TCP " + PrinterService.PORT);
        else if (!PrinterService.lastError.isEmpty()) status.setText("ERRO: " + PrinterService.lastError);
        else status.setText("PARADA  |  Inicie para receber impressoes");
        addresses.setText("Conecte PC e Android ao mesmo Wi-Fi ou use compartilhamento USB.\nEnderecos IPv4 do celular:\n" + ips() + "\nPorta: 9100");
        String[] modes = {"Pronta", "Sem papel", "Tampa aberta", "Offline (status simulado)"};
        simulation.setText("Estado atual: " + modes[getSharedPreferences("device", MODE_PRIVATE).getInt("mode", 0)]);
        jobs.removeAllViews();
        JSONArray list = JobStore.read(this);
        if (list.length() == 0) {
            jobs.addView(label("Nenhum cupom recebido. Use o botao de teste acima.", 14, MUTED, false));
            return;
        }
        for (int i = 0; i < Math.min(list.length(), 12); i++) {
            JSONObject item = list.optJSONObject(i);
            if (item == null) continue;
            LinearLayout card = new LinearLayout(this);
            card.setOrientation(LinearLayout.VERTICAL);
            card.setPadding(dp(12), dp(12), dp(12), dp(12));
            GradientDrawable bg = new GradientDrawable();
            bg.setColor(Color.rgb(247, 247, 247));
            bg.setCornerRadius(dp(4));
            card.setBackground(bg);
            LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(-1, -2);
            params.setMargins(0, dp(10), 0, 0);
            jobs.addView(card, params);
            card.addView(label(item.optString("time") + "  |  " + item.optInt("bytes") + " bytes  |  cortes: " + item.optInt("cuts"), 12, MUTED, false));
            card.addView(label("Origem: " + item.optString("source"), 12, MUTED, false));
            TextView receipt = label(item.optString("text"), 14, INK, false);
            receipt.setTypeface(Typeface.MONOSPACE);
            receipt.setTextIsSelectable(true);
            card.addView(receipt);
            TextView hex = label("HEX: " + item.optString("hex"), 10, MUTED, false);
            hex.setTextIsSelectable(true);
            card.addView(hex);
        }
    }

    private static String ips() {
        StringBuilder result = new StringBuilder();
        try {
            Enumeration<NetworkInterface> interfaces = NetworkInterface.getNetworkInterfaces();
            for (NetworkInterface net : Collections.list(interfaces)) {
                if (!net.isUp() || net.isLoopback()) continue;
                for (java.net.InetAddress addr : Collections.list(net.getInetAddresses())) {
                    if (addr instanceof Inet4Address && !addr.isLoopbackAddress()) {
                        result.append(net.getName()).append(": ").append(addr.getHostAddress()).append('\n');
                    }
                }
            }
        } catch (Exception ignored) { }
        return result.length() == 0 ? "Sem conexao local detectada" : result.toString().trim();
    }

    private Button button(String name, int color) {
        Button b = new Button(this);
        b.setText(name);
        b.setAllCaps(false);
        b.setTextSize(15);
        b.setTextColor(Color.WHITE);
        GradientDrawable bg = new GradientDrawable();
        bg.setColor(color);
        bg.setCornerRadius(dp(5));
        b.setBackground(bg);
        LinearLayout.LayoutParams p = new LinearLayout.LayoutParams(-1, dp(50));
        p.setMargins(0, dp(7), 0, 0);
        b.setLayoutParams(p);
        return b;
    }

    private TextView label(String text, int sp, int color, boolean bold) {
        TextView t = new TextView(this);
        t.setText(text);
        t.setTextSize(sp);
        t.setTextColor(color);
        if (bold) t.setTypeface(Typeface.DEFAULT, Typeface.BOLD);
        t.setLineSpacing(dp(2), 1);
        return t;
    }

    private View gap(int size) {
        View v = new View(this);
        v.setLayoutParams(new LinearLayout.LayoutParams(1, dp(size)));
        return v;
    }
    private int dp(int value) { return Math.round(value * getResources().getDisplayMetrics().density); }
    @Override protected void onStart() { super.onStart(); timer.run(); }
    @Override protected void onStop() { handler.removeCallbacks(timer); super.onStop(); }
}
