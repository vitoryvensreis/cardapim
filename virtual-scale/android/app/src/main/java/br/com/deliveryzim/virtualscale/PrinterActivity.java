package br.com.deliveryzim.virtualscale;

import android.app.Activity;
import android.graphics.Bitmap;
import android.graphics.Canvas;
import android.graphics.Color;
import android.graphics.Paint;
import android.graphics.Rect;
import android.graphics.Typeface;
import android.graphics.drawable.BitmapDrawable;
import android.graphics.drawable.GradientDrawable;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.view.View;
import android.view.WindowManager;
import android.widget.AdapterView;
import android.widget.ArrayAdapter;
import android.widget.Button;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.Spinner;
import android.widget.TextView;

import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.net.Inet4Address;
import java.net.InetAddress;
import java.net.InetSocketAddress;
import java.net.NetworkInterface;
import java.net.ServerSocket;
import java.net.Socket;
import java.net.SocketTimeoutException;
import java.text.SimpleDateFormat;
import java.util.ArrayList;
import java.util.Collections;
import java.util.Date;
import java.util.List;
import java.util.Locale;

/**
 * Impressora térmica virtual (somente teste): recebe na porta 9100 os mesmos bytes ESC/POS que iriam para a
 * impressora e desenha cada cupom na tela, inclusive o cupom em IMAGEM do Agent 0.9+ (GS v 0), a logo, o negrito,
 * a letra dupla e o espaço antes do corte. No Windows, a impressora é criada como "Generic / Text Only" numa
 * porta TCP/IP (RAW) com o IP do celular.
 */
public final class PrinterActivity extends Activity {
    public static final int PORTA = 9100;
    private static final int MAX_CUPONS = 30;
    private static final int MAX_BYTES = 8 * 1024 * 1024;
    private static final int WINE = Color.rgb(101, 20, 36);
    private static final int ORANGE = Color.rgb(255, 88, 37);
    private static final int DARK = Color.rgb(39, 39, 39);
    private static final int GRAY = Color.rgb(102, 98, 102);
    private static final int BG = Color.rgb(248, 247, 247);

    private final Handler handler = new Handler(Looper.getMainLooper());
    private final List<Recebido> recebidos = new ArrayList<>();
    private volatile ServerSocket servidor;
    private volatile boolean desligando;
    private TextView status;
    private LinearLayout lista;
    private TextView vazio;
    private Spinner papel;
    private int contador;

    /** Cupom recebido e quando chegou. */
    private static final class Recebido {
        final EscPos.Cupom cupom;
        final int numero;
        final String hora;
        final int bytes;
        Recebido(EscPos.Cupom cupom, int numero, String hora, int bytes) {
            this.cupom = cupom; this.numero = numero; this.hora = hora; this.bytes = bytes;
        }
    }

    @Override public void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        getWindow().setStatusBarColor(WINE);
        getWindow().setNavigationBarColor(BG);
        montarTela();
        iniciar();
    }

    @Override protected void onDestroy() {
        parar();
        super.onDestroy();
    }

    // ---------- tela ----------
    private int dp(int x) { return (int) (getResources().getDisplayMetrics().density * x + .5f); }

    private GradientDrawable fundo(int cor, int borda) {
        GradientDrawable g = new GradientDrawable();
        g.setColor(cor);
        g.setCornerRadius(dp(12));
        if (borda != 0) g.setStroke(dp(1), borda);
        return g;
    }

    private TextView texto(String s, int tamanho, int cor, boolean negrito) {
        TextView t = new TextView(this);
        t.setText(s);
        t.setTextSize(tamanho);
        t.setTextColor(cor);
        if (negrito) t.setTypeface(Typeface.DEFAULT, Typeface.BOLD);
        return t;
    }

    private LinearLayout coluna() {
        LinearLayout c = new LinearLayout(this);
        c.setOrientation(LinearLayout.VERTICAL);
        return c;
    }

    private Button botao(String texto, boolean principal, View.OnClickListener clique) {
        Button b = new Button(this);
        b.setAllCaps(false);
        b.setText(texto);
        b.setTextSize(14);
        b.setTypeface(Typeface.DEFAULT, Typeface.BOLD);
        b.setTextColor(principal ? Color.WHITE : WINE);
        b.setBackground(fundo(principal ? WINE : BG, principal ? 0 : Color.rgb(218, 208, 210)));
        b.setMinHeight(dp(48));
        b.setOnClickListener(clique);
        return b;
    }

    private void cartao(LinearLayout pai, LinearLayout c) {
        c.setPadding(dp(16), dp(16), dp(16), dp(16));
        c.setBackground(fundo(Color.WHITE, Color.rgb(232, 227, 228)));
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(-1, -2);
        lp.bottomMargin = dp(14);
        pai.addView(c, lp);
    }

    private void montarTela() {
        ScrollView rolagem = new ScrollView(this);
        rolagem.setFillViewport(true);
        rolagem.setBackgroundColor(BG);
        LinearLayout tudo = coluna();
        rolagem.addView(tudo);
        setContentView(rolagem);

        LinearLayout topo = coluna();
        topo.setPadding(dp(22), dp(26), dp(22), dp(22));
        topo.setBackgroundColor(WINE);
        topo.addView(texto("DELIVERYZIM", 13, Color.WHITE, true));
        topo.addView(texto("Impressora virtual", 26, Color.WHITE, true));
        topo.addView(texto("Veja na tela como o cupom sai na térmica.", 14, Color.WHITE, false));
        tudo.addView(topo);
        View faixa = new View(this);
        faixa.setBackgroundColor(ORANGE);
        tudo.addView(faixa, new LinearLayout.LayoutParams(-1, dp(4)));

        LinearLayout corpo = coluna();
        corpo.setPadding(dp(16), dp(18), dp(16), dp(22));
        tudo.addView(corpo);

        LinearLayout conexao = coluna();
        conexao.addView(texto("CONEXÃO PELO WI-FI", 12, WINE, true));
        status = texto("", 15, DARK, true);
        status.setPadding(0, dp(8), 0, dp(10));
        conexao.addView(status);
        LinearLayout botoes = new LinearLayout(this);
        botoes.setOrientation(LinearLayout.HORIZONTAL);
        LinearLayout.LayoutParams metade = new LinearLayout.LayoutParams(0, -2, 1);
        metade.rightMargin = dp(6);
        botoes.addView(botao("Ligar", true, v -> iniciar()), metade);
        LinearLayout.LayoutParams metade2 = new LinearLayout.LayoutParams(0, -2, 1);
        metade2.leftMargin = dp(6);
        botoes.addView(botao("Desligar", false, v -> parar()), metade2);
        conexao.addView(botoes);
        TextView ajuda = texto("No Windows: Adicionar impressora > TCP/IP > IP acima, porta 9100 (RAW), driver "
                + "\"Generic / Text Only\". Depois escolha essa impressora no Deliveryzim Agent. "
                + "Celular e computador na mesma rede.", 12, GRAY, false);
        ajuda.setPadding(0, dp(10), 0, 0);
        conexao.addView(ajuda);
        cartao(corpo, conexao);

        LinearLayout ajustes = coluna();
        ajustes.addView(texto("Largura do papel", 13, DARK, true));
        papel = new Spinner(this);
        ArrayAdapter<String> a = new ArrayAdapter<>(this, android.R.layout.simple_spinner_item,
                new String[]{"Automático (pelo cupom)", "80 mm", "58 mm"});
        a.setDropDownViewResource(android.R.layout.simple_spinner_dropdown_item);
        papel.setAdapter(a);
        papel.setOnItemSelectedListener(new AdapterView.OnItemSelectedListener() {
            @Override public void onItemSelected(AdapterView<?> p, View v, int pos, long id) { redesenhar(); }
            @Override public void onNothingSelected(AdapterView<?> p) {}
        });
        ajustes.addView(papel);
        ajustes.addView(botao("Limpar cupons", false, v -> { recebidos.clear(); redesenhar(); }));
        cartao(corpo, ajustes);

        corpo.addView(texto("CUPONS RECEBIDOS (o mais novo primeiro)", 12, WINE, true));
        vazio = texto("Nenhum cupom ainda. Mande um teste pelo Deliveryzim Agent ou pela Gerência > Impressoras.",
                13, GRAY, false);
        vazio.setPadding(0, dp(8), 0, dp(8));
        corpo.addView(vazio);
        lista = coluna();
        corpo.addView(lista);
        atualizarStatus();
    }

    private void atualizarStatus() {
        boolean ligado = servidor != null;
        status.setText((ligado ? "Ligada, esperando cupons" : "Desligada") + "\n" + ipLocal() + " : " + PORTA);
        if (ligado) getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
        else getWindow().clearFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
    }

    private static String ipLocal() {
        try {
            for (NetworkInterface ni : Collections.list(NetworkInterface.getNetworkInterfaces())) {
                if (!ni.isUp() || ni.isLoopback()) continue;
                for (InetAddress addr : Collections.list(ni.getInetAddresses())) {
                    if (addr instanceof Inet4Address && addr.isSiteLocalAddress()) return addr.getHostAddress();
                }
            }
        } catch (Exception ignored) {
            // sem rede: mostra o aviso abaixo
        }
        return "sem Wi-Fi";
    }

    // ---------- servidor TCP 9100 ----------
    private void iniciar() {
        if (servidor != null) return;
        desligando = false;
        Thread t = new Thread(() -> {
            try (ServerSocket s = new ServerSocket()) {
                s.setReuseAddress(true);
                s.bind(new InetSocketAddress(PORTA));
                servidor = s;
                handler.post(this::atualizarStatus);
                while (!s.isClosed()) {
                    Socket cliente = s.accept();
                    Thread at = new Thread(() -> atender(cliente), "impressora-cliente");
                    at.setDaemon(true);
                    at.start();
                }
            } catch (IOException e) {
                servidor = null;
                if (!desligando) {
                    final String msg = e.getMessage() == null ? "erro" : e.getMessage();
                    handler.post(() -> {
                        atualizarStatus();
                        status.setText("Não foi possível ligar a porta " + PORTA + ": " + msg
                                + "\nFeche outro app de impressora virtual e toque em Ligar.");
                    });
                    return;
                }
            }
            servidor = null;
            handler.post(this::atualizarStatus);
        }, "impressora-servidor");
        t.setDaemon(true);
        t.start();
    }

    private void parar() {
        ServerSocket s = servidor;
        desligando = true;
        servidor = null;
        if (s != null) {
            try { s.close(); } catch (IOException ignored) { /* ja fechado */ }
        }
        atualizarStatus();
    }

    /** Lê o envio inteiro (até o Windows fechar a conexão ou 1,5 s sem bytes) e mostra os cupons. */
    private void atender(Socket cliente) {
        ByteArrayOutputStream dados = new ByteArrayOutputStream();
        try (Socket c = cliente) {
            c.setSoTimeout(1500);
            InputStream in = c.getInputStream();
            byte[] buf = new byte[16384];
            long inicio = System.currentTimeMillis();
            while (dados.size() < MAX_BYTES) {
                int n;
                try {
                    n = in.read(buf);
                } catch (SocketTimeoutException e) {
                    if (dados.size() > 0 || System.currentTimeMillis() - inicio > 30000) break;
                    continue;
                }
                if (n < 0) break;
                dados.write(buf, 0, n);
            }
        } catch (IOException ignored) {
            // conexao caiu: mostra o que chegou
        }
        if (dados.size() == 0) return;
        final byte[] bytes = dados.toByteArray();
        final EscPos.Resultado r = EscPos.ler(bytes);
        handler.post(() -> receber(r, bytes.length));
    }

    private void receber(EscPos.Resultado r, int bytes) {
        String hora = new SimpleDateFormat("HH:mm:ss", Locale.forLanguageTag("pt-BR")).format(new Date());
        for (EscPos.Cupom c : r.cupons) {
            if (c.vazio()) continue;
            recebidos.add(0, new Recebido(c, ++contador, hora, bytes));
        }
        while (recebidos.size() > MAX_CUPONS) recebidos.remove(recebidos.size() - 1);
        redesenhar();
    }

    // ---------- desenho ----------
    private int larguraPapel(EscPos.Cupom c) {
        int escolha = papel == null ? 0 : papel.getSelectedItemPosition();
        if (escolha == 1) return 80;
        if (escolha == 2) return 58;
        if (c.larguraImagem > 0) return c.larguraImagem > 400 ? 80 : 58;
        return 80;
    }

    private void redesenhar() {
        if (lista == null) return;
        lista.removeAllViews();
        vazio.setVisibility(recebidos.isEmpty() ? View.VISIBLE : View.GONE);
        for (Recebido rc : recebidos) {
            int mm = larguraPapel(rc.cupom);
            LinearLayout c = coluna();
            c.addView(texto("Cupom " + rc.numero + " · " + rc.hora + " · papel " + mm + " mm"
                    + (rc.cupom.cortado ? " · cortado" : " · sem corte"), 12, GRAY, true));
            ImageView img = new ImageView(this);
            BitmapDrawable bd = new BitmapDrawable(getResources(), desenhar(rc.cupom, mm));
            bd.setFilterBitmap(false); // pontos nítidos, como no papel
            img.setImageDrawable(bd);
            img.setAdjustViewBounds(true);
            img.setScaleType(ImageView.ScaleType.FIT_CENTER);
            img.setBackgroundColor(Color.WHITE);
            img.setContentDescription("Cupom " + rc.numero + " como sai na impressora");
            LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(-1, -2);
            lp.topMargin = dp(8);
            c.addView(img, lp);
            if (rc.cupom.cortado) {
                TextView corte = texto("- - - - - - - corte do papel - - - - - - -", 11, GRAY, false);
                corte.setGravity(android.view.Gravity.CENTER_HORIZONTAL);
                corte.setPadding(0, dp(6), 0, 0);
                c.addView(corte);
            }
            cartao(lista, c);
        }
    }

    /** Desenha o cupom no tamanho real da térmica (58 mm = 384 pontos, 80 mm = 576). */
    static Bitmap desenhar(EscPos.Cupom cupom, int mm) {
        int largura = mm == 58 ? 384 : 576;
        int colunas = mm == 58 ? 32 : 48;
        int margem = 4;
        Paint p = new Paint(Paint.ANTI_ALIAS_FLAG);
        p.setColor(Color.BLACK);
        p.setTypeface(Typeface.MONOSPACE);
        p.setTextSize(100f);
        float base = 100f * (largura - 2 * margem) / (colunas * p.measureText("0"));
        float larguraLetra = (largura - 2f * margem) / colunas;
        int alturaLinha = (int) Math.ceil(base * 1.3f);

        int altura = margem * 2;
        for (EscPos.Parte parte : cupom.partes) altura += alturaDe(parte, largura, alturaLinha);
        altura = Math.max(40, Math.min(altura, 30000));

        Bitmap bmp = Bitmap.createBitmap(largura, altura, Bitmap.Config.ARGB_8888);
        Canvas cv = new Canvas(bmp);
        cv.drawColor(Color.WHITE);
        Typeface normal = Typeface.MONOSPACE;
        Typeface forte = Typeface.create(Typeface.MONOSPACE, Typeface.BOLD);
        int y = margem;
        for (EscPos.Parte parte : cupom.partes) {
            if (y >= altura) break;
            int h = alturaDe(parte, largura, alturaLinha);
            if (parte.tipo == EscPos.Parte.TEXTO) {
                int maiorAltura = 1;
                float total = 0;
                for (EscPos.Trecho t : parte.trechos) {
                    maiorAltura = Math.max(maiorAltura, t.altura);
                    total += larguraLetra * t.largura * t.texto.length();
                }
                float x = parte.alinhamento == 1 ? (largura - total) / 2f : parte.alinhamento == 2 ? largura - margem - total : margem;
                x = Math.max(0, x);
                float linhaBase = y + alturaLinha * maiorAltura * 0.8f;
                for (EscPos.Trecho t : parte.trechos) {
                    p.setTypeface(t.negrito ? forte : normal);
                    p.setFakeBoldText(t.negrito);
                    p.setTextSize(base * t.altura);
                    p.setTextScaleX(t.largura / (float) t.altura);
                    // Letra por letra na grade da térmica (colunas fixas, como o papel).
                    for (int k = 0; k < t.texto.length(); k++) {
                        cv.drawText(String.valueOf(t.texto.charAt(k)), x, linhaBase, p);
                        x += larguraLetra * t.largura;
                    }
                }
                p.setTextScaleX(1f);
            } else if (parte.tipo == EscPos.Parte.IMAGEM && parte.larguraPontos > 0 && parte.alturaPontos > 0) {
                int w = parte.larguraPontos;
                int hi = parte.alturaPontos;
                int[] pixels = new int[w * hi];
                for (int yy = 0; yy < hi; yy++) {
                    for (int xx = 0; xx < w; xx++) pixels[yy * w + xx] = parte.preto(xx, yy) ? Color.BLACK : Color.WHITE;
                }
                Bitmap img = Bitmap.createBitmap(pixels, w, hi, Bitmap.Config.ARGB_8888);
                int destW = Math.min(w, largura);
                int destH = w > largura ? Math.round(hi * (largura / (float) w)) : hi;
                int x = parte.alinhamento == 1 ? (largura - destW) / 2 : parte.alinhamento == 2 ? largura - destW : 0;
                cv.drawBitmap(img, null, new Rect(x, y, x + destW, y + destH), null);
                img.recycle();
            }
            y += h;
        }
        return bmp;
    }

    private static int alturaDe(EscPos.Parte parte, int largura, int alturaLinha) {
        if (parte.tipo == EscPos.Parte.TEXTO) {
            int maior = 1;
            for (EscPos.Trecho t : parte.trechos) maior = Math.max(maior, t.altura);
            return alturaLinha * maior;
        }
        if (parte.tipo == EscPos.Parte.IMAGEM) {
            return parte.larguraPontos > largura
                    ? Math.round(parte.alturaPontos * (largura / (float) parte.larguraPontos))
                    : parte.alturaPontos;
        }
        return alturaLinha * Math.max(0, parte.linhas);
    }
}
