package br.com.deliveryzim.virtualscale;

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
import android.text.InputType;
import android.view.Gravity;
import android.view.View;
import android.widget.ArrayAdapter;
import android.widget.Button;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.Spinner;
import android.widget.TextView;
import android.widget.Toast;

import java.math.BigDecimal;
import java.net.Inet4Address;
import java.net.InetAddress;
import java.net.NetworkInterface;
import java.net.SocketException;
import java.util.Collections;
import java.util.Locale;

/** Interface sem hardware: entrada explícita de peso e estados simulados. */
public final class MainActivity extends Activity {
    private static final int WINE = Color.rgb(101, 20, 36);
    private static final int ORANGE = Color.rgb(255, 88, 37);
    private static final int DARK = Color.rgb(39, 39, 39);
    private static final int GRAY = Color.rgb(102, 98, 102);
    private static final int WHITE = Color.WHITE;
    private static final int BG = Color.rgb(248, 247, 247);

    private final Handler handler = new Handler(Looper.getMainLooper());
    private EditText input;
    private Spinner protocol;
    private Spinner state;
    private TextView display;
    private TextView connection;
    private TextView wire;
    private TextView caution;
    private boolean active;

    @Override public void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        getWindow().setStatusBarColor(WINE);
        getWindow().setNavigationBarColor(BG);
        buildScreen();
        if (Build.VERSION.SDK_INT >= 33
                && checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS)
                    != PackageManager.PERMISSION_GRANTED) {
            requestPermissions(new String[]{Manifest.permission.POST_NOTIFICATIONS}, 17);
        }
    }

    private int dp(int x) { return (int) (getResources().getDisplayMetrics().density * x + .5f); }

    private GradientDrawable background(int color, int stroke) {
        GradientDrawable shape = new GradientDrawable();
        shape.setColor(color);
        shape.setCornerRadius(dp(12));
        if (stroke != 0) shape.setStroke(dp(1), stroke);
        return shape;
    }

    private TextView text(String s, int size, int color, boolean bold) {
        TextView t = new TextView(this);
        t.setText(s);
        t.setTextSize(size);
        t.setTextColor(color);
        if (bold) t.setTypeface(Typeface.DEFAULT, Typeface.BOLD);
        return t;
    }

    private LinearLayout column() {
        LinearLayout c = new LinearLayout(this);
        c.setOrientation(LinearLayout.VERTICAL);
        return c;
    }

    private void spacing(LinearLayout p, int pixels) {
        View gap = new View(this);
        p.addView(gap, new LinearLayout.LayoutParams(1, dp(pixels)));
    }

    private void rowCard(LinearLayout root, LinearLayout card) {
        card.setPadding(dp(18), dp(18), dp(18), dp(18));
        card.setBackground(background(WHITE, Color.rgb(232, 227, 228)));
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(-1, -2);
        lp.bottomMargin = dp(14);
        root.addView(card, lp);
    }

    private Button button(String caption, boolean main, View.OnClickListener click) {
        Button b = new Button(this);
        b.setAllCaps(false);
        b.setText(caption);
        b.setTextSize(14);
        b.setTypeface(Typeface.DEFAULT, Typeface.BOLD);
        b.setTextColor(main ? WHITE : WINE);
        b.setBackground(background(main ? WINE : BG, main ? 0 : Color.rgb(218, 208, 210)));
        b.setOnClickListener(click);
        b.setMinHeight(dp(50));
        return b;
    }

    private Spinner options(String[] labels, int selected) {
        Spinner s = new Spinner(this);
        ArrayAdapter<String> a = new ArrayAdapter<>(this,
                android.R.layout.simple_spinner_item, labels);
        a.setDropDownViewResource(android.R.layout.simple_spinner_dropdown_item);
        s.setAdapter(a);
        s.setSelection(selected);
        return s;
    }

    private void buildScreen() {
        ScrollView scroll = new ScrollView(this);
        scroll.setFillViewport(true);
        scroll.setBackgroundColor(BG);
        LinearLayout whole = column();
        scroll.addView(whole);
        setContentView(scroll);

        LinearLayout header = column();
        header.setPadding(dp(23), dp(28), dp(23), dp(26));
        header.setBackgroundColor(WINE);
        header.addView(text("DELIVERYZIM", 13, WHITE, true));
        spacing(header, 8);
        header.addView(text("Balança virtual", 27, WHITE, true));
        spacing(header, 6);
        header.addView(text("Simule leituras sem equipamento físico.", 14, WHITE, false));
        whole.addView(header);

        View line = new View(this);
        line.setBackgroundColor(ORANGE);
        whole.addView(line, new LinearLayout.LayoutParams(-1, dp(4)));

        LinearLayout body = column();
        body.setPadding(dp(18), dp(20), dp(18), dp(22));
        whole.addView(body);

        caution = text("MODO DE TESTE — NÃO É UMA BALANÇA REAL.\n"
                + "Não use o peso simulado para cobrar consumidores.", 13, WINE, true);
        caution.setPadding(dp(16), dp(13), dp(16), dp(13));
        caution.setBackground(background(Color.rgb(255, 240, 231), ORANGE));
        body.addView(caution);
        spacing(body, 16);

        LinearLayout main = column();
        main.addView(text("PESO LÍQUIDO SIMULADO", 12, GRAY, true));
        spacing(main, 9);
        input = new EditText(this);
        input.setSingleLine(true);
        input.setText("0,350");
        input.setTextSize(25);
        input.setTextColor(WINE);
        input.setSelectAllOnFocus(true);
        input.setInputType(InputType.TYPE_CLASS_NUMBER | InputType.TYPE_NUMBER_FLAG_DECIMAL);
        input.setHint("0,350");
        input.setContentDescription("Peso líquido em quilogramas, com até três casas decimais");
        main.addView(input);
        main.addView(text("Digite em kg. Ex.: 0,350 = 350 g. Limite: 99,999 kg.", 12, GRAY, false));
        spacing(main, 18);

        main.addView(text("Estado da balança", 13, DARK, true));
        state = options(new String[]{"Estável", "Instável (movimento)", "Sobrecarga"}, 0);
        main.addView(state);
        spacing(main, 14);

        main.addView(text("Protocolo de transmissão", 13, DARK, true));
        protocol = options(new String[]{
                "ASCII contínuo (Deliveryzim)",
                "Toledo Prix III / Prt5 (ENQ)",
                "Urano PROT3 (contínuo)"
        }, 0);
        main.addView(protocol);
        spacing(main, 16);
        main.addView(button("Aplicar peso e estado", true, v -> apply()));
        rowCard(body, main);

        LinearLayout network = column();
        network.addView(text("CONEXÃO PELO WI-FI", 12, WINE, true));
        spacing(network, 9);
        connection = text("", 14, DARK, true);
        network.addView(connection);
        spacing(network, 13);
        network.addView(button("Iniciar servidor de teste", true, v -> start()));
        spacing(network, 9);
        network.addView(button("Parar simulador", false, v -> stop()));
        spacing(network, 12);
        network.addView(text("Celular e computador na mesma rede. No Agent, use "
                + "o IP mostrado acima e porta 9101. Não exponha essa porta à internet.",
                12, GRAY, false));
        rowCard(body, network);

        LinearLayout diagnostics = column();
        diagnostics.addView(text("DIAGNÓSTICO", 12, WINE, true));
        spacing(diagnostics, 9);
        display = text("", 22, WINE, true);
        diagnostics.addView(display);
        spacing(diagnostics, 6);
        wire = text("", 12, GRAY, false);
        diagnostics.addView(wire);
        rowCard(body, diagnostics);
    }

    private int grams() {
        String str = input.getText().toString().trim().replace(",", ".");
        if (!str.matches("(?:\\d{1,2})(?:\\.\\d{1,3})?")) {
            throw new IllegalArgumentException("Informe entre 0 e 99,999 kg, com até 3 decimais.");
        }
        return new BigDecimal(str).movePointRight(3).intValueExact();
    }

    private Intent serviceIntent(String action) {
        int value = grams();
        Intent i = new Intent(this, ScaleService.class);
        i.setAction(action);
        i.putExtra("grams", value);
        i.putExtra("profile", protocol.getSelectedItemPosition());
        i.putExtra("stable", state.getSelectedItemPosition() == 0);
        i.putExtra("overload", state.getSelectedItemPosition() == 2);
        return i;
    }

    private void apply() {
        try {
            if (!ScaleService.isRunning()) {
                Toast.makeText(this, "Inicie o servidor antes de enviar leituras.", Toast.LENGTH_LONG).show();
                return;
            }
            startService(serviceIntent(ScaleService.UPDATE));
            Toast.makeText(this, "Peso simulado atualizado.", Toast.LENGTH_SHORT).show();
        } catch (IllegalArgumentException | ArithmeticException ex) {
            input.setError(ex.getMessage());
        }
    }

    private void start() {
        try {
            Intent intent = serviceIntent(ScaleService.START);
            if (Build.VERSION.SDK_INT >= 26) startForegroundService(intent);
            else startService(intent);
        } catch (IllegalArgumentException | ArithmeticException ex) {
            input.setError(ex.getMessage());
        } catch (SecurityException ex) {
            Toast.makeText(this, "Permissão de serviço negada: " + ex.getMessage(), Toast.LENGTH_LONG).show();
        }
    }

    private void stop() {
        Intent intent = new Intent(this, ScaleService.class);
        intent.setAction(ScaleService.STOP);
        startService(intent);
    }

    private static String privateIp() {
        try {
            for (NetworkInterface ni : Collections.list(NetworkInterface.getNetworkInterfaces())) {
                if (!ni.isUp() || ni.isLoopback()) continue;
                for (InetAddress addr : Collections.list(ni.getInetAddresses())) {
                    if (addr instanceof Inet4Address && addr.isSiteLocalAddress()) {
                        return addr.getHostAddress();
                    }
                }
            }
        } catch (SocketException ignored) { }
        return "IP indisponível — conecte o Wi-Fi";
    }

    private final Runnable updater = new Runnable() {
        @Override public void run() {
            if (!active) return;
            connection.setText((ScaleService.isRunning() ? "Servidor ligado" : "Servidor desligado")
                    + "\n" + privateIp() + " : " + ScaleProtocol.PORT
                    + "\nConexões: " + ScaleService.clients()
                    + " | Tramas enviadas: " + ScaleService.frames()
                    + "\n" + ScaleService.feedback());
            int p = ScaleService.currentProfile();
            int g = ScaleService.currentGrams();
            display.setText(String.format(Locale.forLanguageTag("pt-BR"), "%d,%03d kg",
                    g / 1000, g % 1000));
            wire.setText("Bytes enviados (HEX): "
                    + ScaleProtocol.hex(ScaleProtocol.frame(g,
                    ScaleService.currentStable(), ScaleService.currentOverload(), p))
                    + "\n" + (p == ScaleProtocol.TOLEDO_PRT5
                    ? "Modo sob demanda: responde ao comando ENQ (05)."
                    : "Modo contínuo: transmite aproximadamente a cada 350 ms."));
            handler.postDelayed(this, 500);
        }
    };

    @Override protected void onResume() {
        super.onResume();
        active = true;
        handler.post(updater);
    }

    @Override protected void onPause() {
        active = false;
        handler.removeCallbacks(updater);
        super.onPause();
    }
}
