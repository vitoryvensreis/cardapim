package br.com.deliveryzim.virtualscale;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.Intent;
import android.os.Build;
import android.os.IBinder;

import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.InetAddress;
import java.net.InetSocketAddress;
import java.net.ServerSocket;
import java.net.Socket;
import java.net.SocketTimeoutException;
import java.util.concurrent.CopyOnWriteArrayList;
import java.util.concurrent.atomic.AtomicInteger;

/**
 * Simulador de bancada via TCP local. NÃO mede massa, NÃO é instrumento metrológico.
 * App explicitamente iniciado, serviço foreground e clientes limitados à rede privada.
 */
public final class ScaleService extends Service {
    public static final String START = "br.com.deliveryzim.virtualscale.START";
    public static final String STOP = "br.com.deliveryzim.virtualscale.STOP";
    public static final String UPDATE = "br.com.deliveryzim.virtualscale.UPDATE";
    private static final String CHANNEL = "virtual_scale";
    private static final AtomicInteger CLIENTS = new AtomicInteger();
    private static final AtomicInteger FRAMES = new AtomicInteger();
    private static final CopyOnWriteArrayList<Socket> OPEN = new CopyOnWriteArrayList<>();

    private static volatile boolean running;
    private static volatile int grams = 350;
    private static volatile boolean stable = true;
    private static volatile boolean overload;
    private static volatile int profile = ScaleProtocol.ASCII;
    private static volatile String feedback = "Desligado";

    private volatile boolean stop;
    private ServerSocket listener;
    private Thread acceptThread;

    public static boolean isRunning() { return running; }
    public static int clients() { return CLIENTS.get(); }
    public static int frames() { return FRAMES.get(); }
    public static int currentGrams() { return grams; }
    public static int currentProfile() { return profile; }
    public static boolean currentStable() { return stable; }
    public static boolean currentOverload() { return overload; }
    public static String feedback() { return feedback; }

    @Override public IBinder onBind(Intent intent) { return null; }

    @Override public int onStartCommand(Intent intent, int flags, int startId) {
        String action = intent == null ? "" : intent.getAction();
        if (STOP.equals(action)) {
            stopSelf();
            return START_NOT_STICKY;
        }
        if (intent != null && (UPDATE.equals(action) || START.equals(action))) {
            int inputGrams = intent.getIntExtra("grams", grams);
            int inputProfile = intent.getIntExtra("profile", profile);
            if (inputGrams >= 0 && inputGrams <= 99999
                    && inputProfile >= ScaleProtocol.ASCII && inputProfile <= ScaleProtocol.URANO_PROT3) {
                grams = inputGrams;
                profile = inputProfile;
                stable = intent.getBooleanExtra("stable", stable);
                overload = intent.getBooleanExtra("overload", overload);
            }
        }
        if (!running) {
            setupForeground();
            stop = false;
            running = true;
            feedback = "Iniciando servidor TCP local";
            acceptThread = new Thread(this::listen, "virtual-scale-listen");
            acceptThread.setDaemon(true);
            acceptThread.start();
        }
        return START_NOT_STICKY;
    }

    private void setupForeground() {
        NotificationManager manager = getSystemService(NotificationManager.class);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            manager.createNotificationChannel(new NotificationChannel(CHANNEL,
                    "Simulador de balança", NotificationManager.IMPORTANCE_LOW));
        }
        Intent open = new Intent(this, MainActivity.class);
        PendingIntent pending = PendingIntent.getActivity(this, 0, open,
                PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
        Notification notice = new Notification.Builder(this, CHANNEL)
                .setSmallIcon(android.R.drawable.ic_menu_manage)
                .setContentTitle("Deliveryzim — balança simulada")
                .setContentText("Simulação de peso em rede local. Não usar para vendas.")
                .setOngoing(true)
                .setContentIntent(pending)
                .build();
        startForeground(4310, notice);
    }

    private void listen() {
        try (ServerSocket server = new ServerSocket()) {
            listener = server;
            server.setReuseAddress(true);
            server.bind(new InetSocketAddress("0.0.0.0", ScaleProtocol.PORT));
            server.setSoTimeout(500);
            feedback = "Servidor ativo: porta " + ScaleProtocol.PORT;
            while (!stop) {
                try {
                    Socket socket = server.accept();
                    InetAddress remote = socket.getInetAddress();
                    if (!(remote.isSiteLocalAddress() || remote.isLoopbackAddress())
                            || CLIENTS.get() >= 4) {
                        socket.close();
                        continue;
                    }
                    socket.setSoTimeout(100);
                    socket.setTcpNoDelay(true);
                    OPEN.add(socket);
                    CLIENTS.incrementAndGet();
                    Thread client = new Thread(() -> serve(socket), "virtual-scale-client");
                    client.setDaemon(true);
                    client.start();
                } catch (SocketTimeoutException ignored) {
                    // Permite sair rapidamente após o botão Parar.
                }
            }
        } catch (IOException ex) {
            if (!stop) feedback = "Falha na porta " + ScaleProtocol.PORT + ": " + ex.getMessage();
        } finally {
            listener = null;
            running = false;
        }
    }

    private void serve(Socket socket) {
        try (Socket closeable = socket) {
            InputStream in = socket.getInputStream();
            OutputStream out = socket.getOutputStream();
            long nextContinuous = 0;
            while (!stop && !socket.isClosed()) {
                try {
                    int input = in.read();
                    if (input == -1) break;
                    // Toledo Prt5 exige ENQ: não publica continuamente.
                    if (input == 0x05 && profile == ScaleProtocol.TOLEDO_PRT5) {
                        transmit(out);
                    }
                } catch (SocketTimeoutException ignored) {
                    // No modo contínuo, publicar a cada 350 ms.
                }
                long now = System.nanoTime();
                if (profile != ScaleProtocol.TOLEDO_PRT5 && now >= nextContinuous) {
                    transmit(out);
                    nextContinuous = now + 350_000_000L;
                }
            }
        } catch (IOException ignored) {
            // Desconexão de teste não pode encerrar o simulador.
        } finally {
            OPEN.remove(socket);
            CLIENTS.decrementAndGet();
        }
    }

    private void transmit(OutputStream out) throws IOException {
        byte[] frame = ScaleProtocol.frame(grams, stable, overload, profile);
        out.write(frame);
        out.flush();
        FRAMES.incrementAndGet();
    }

    @Override public void onDestroy() {
        stop = true;
        running = false;
        feedback = "Desligado";
        try { if (listener != null) listener.close(); } catch (IOException ignored) {}
        for (Socket socket : OPEN) {
            try { socket.close(); } catch (IOException ignored) {}
        }
        OPEN.clear();
        super.onDestroy();
    }
}
