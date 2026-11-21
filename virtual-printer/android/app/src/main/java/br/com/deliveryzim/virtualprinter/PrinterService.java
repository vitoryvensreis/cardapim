package br.com.deliveryzim.virtualprinter;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.Intent;
import android.os.Build;
import android.os.IBinder;
import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.InetSocketAddress;
import java.net.ServerSocket;
import java.net.Socket;
import java.net.SocketTimeoutException;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.atomic.AtomicInteger;

public final class PrinterService extends Service {
    public static final String STOP = "br.com.deliveryzim.virtualprinter.STOP";
    public static final String MODE = "br.com.deliveryzim.virtualprinter.MODE";
    public static final int PORT = 9100;
    public static volatile boolean running = false;
    public static volatile String lastError = "";
    private static final String CHANNEL = "printer_active";
    private final AtomicInteger clients = new AtomicInteger(0);
    private volatile boolean closing;
    private ServerSocket server;
    private Thread acceptThread;
    private ExecutorService workers;

    @Override public void onCreate() {
        super.onCreate();
        NotificationChannel channel = new NotificationChannel(CHANNEL, "Impressora virtual ativa", NotificationManager.IMPORTANCE_LOW);
        getSystemService(NotificationManager.class).createNotificationChannel(channel);
    }

    @Override public int onStartCommand(Intent intent, int flags, int startId) {
        String action = intent == null ? "" : intent.getAction();
        if (STOP.equals(action)) {
            stopSelf();
            return START_NOT_STICKY;
        }
        if (MODE.equals(action)) {
            int mode = intent.getIntExtra("mode", 0);
            getSharedPreferences("device", MODE_PRIVATE).edit().putInt("mode", mode).apply();
            return START_STICKY;
        }
        startForeground(100, notification());
        if (acceptThread == null || !acceptThread.isAlive()) {
            closing = false;
            workers = Executors.newFixedThreadPool(4);
            acceptThread = new Thread(this::listen, "deliveryzim-tcp-listener");
            acceptThread.start();
        }
        return START_STICKY;
    }

    private Notification notification() {
        Intent open = new Intent(this, MainActivity.class);
        PendingIntent activity = PendingIntent.getActivity(this, 0, open, PendingIntent.FLAG_IMMUTABLE | PendingIntent.FLAG_UPDATE_CURRENT);
        Intent stop = new Intent(this, PrinterService.class).setAction(STOP);
        PendingIntent stopAction = PendingIntent.getService(this, 1, stop, PendingIntent.FLAG_IMMUTABLE | PendingIntent.FLAG_UPDATE_CURRENT);
        return new Notification.Builder(this, CHANNEL)
            .setContentTitle("Deliveryzim Virtual Printer")
            .setContentText("Recebendo trabalhos TCP na porta 9100")
            .setSmallIcon(android.R.drawable.ic_menu_info_details)
            .setOngoing(true)
            .setContentIntent(activity)
            .addAction(new Notification.Action.Builder(null, "Parar", stopAction).build())
            .build();
    }

    private void listen() {
        try (ServerSocket local = new ServerSocket()) {
            server = local;
            local.setReuseAddress(true);
            local.bind(new InetSocketAddress("0.0.0.0", PORT), 16);
            lastError = "";
            running = true;
            while (!closing && !Thread.currentThread().isInterrupted()) {
                Socket accepted = local.accept();
                if (clients.get() >= 4) { accepted.close(); continue; }
                clients.incrementAndGet();
                workers.execute(() -> {
                    try { handle(accepted); }
                    finally { clients.decrementAndGet(); }
                });
            }
        } catch (IOException e) {
            if (!closing) lastError = "Falha ao abrir porta 9100: " + e.getMessage();
        } finally {
            running = false;
        }
    }

    private void handle(Socket client) {
        try (Socket socket = client) {
            socket.setSoTimeout(900);
            InputStream input = socket.getInputStream();
            OutputStream output = socket.getOutputStream();
            ByteArrayOutputStream job = new ByteArrayOutputStream();
            int state = 0;
            int fault = getSharedPreferences("device", MODE_PRIVATE).getInt("mode", 0);
            String source = socket.getInetAddress().getHostAddress();
            long lastActive = System.currentTimeMillis();
            byte[] data = new byte[4096];

            while (!closing) {
                int read;
                try { read = input.read(data); }
                catch (SocketTimeoutException timeout) {
                    if (job.size() > 0) {
                        store(source, job.toByteArray());
                        job.reset();
                    }
                    if (System.currentTimeMillis() - lastActive > 15000) break;
                    continue;
                }
                if (read == -1) break;
                lastActive = System.currentTimeMillis();
                fault = getSharedPreferences("device", MODE_PRIVATE).getInt("mode", fault);
                for (int i = 0; i < read; i++) {
                    int b = data[i] & 0xff;
                    if (state == 0) state = b == 0x10 ? 1 : 0;
                    else if (state == 1) state = b == 0x04 ? 2 : b == 0x10 ? 1 : 0;
                    else {
                        output.write(EscPosDecoder.status(b, fault));
                        output.flush();
                        state = 0;
                    }
                }
                if (job.size() + read > 1024 * 1024) {
                    lastError = "Trabalho ignorado: acima de 1 MB";
                    job.reset();
                    break;
                }
                job.write(data, 0, read);
            }
            if (job.size() > 0) store(source, job.toByteArray());
        } catch (IOException e) {
            if (!closing) lastError = "Cliente desconectado: " + e.getMessage();
        }
    }

    private void store(String source, byte[] data) {
        EscPosDecoder.Result result = EscPosDecoder.decode(data);
        if (!result.printable) return;
        JobStore.add(this, source, data, result);
        sendBroadcast(new Intent("br.com.deliveryzim.virtualprinter.RECEIVED").setPackage(getPackageName()));
    }

    @Override public void onDestroy() {
        closing = true;
        try { if (server != null) server.close(); } catch (IOException ignored) {}
        if (acceptThread != null) acceptThread.interrupt();
        if (workers != null) workers.shutdownNow();
        running = false;
        super.onDestroy();
    }

    @Override public IBinder onBind(Intent intent) { return null; }
}
