package br.com.deliveryzim.virtualprinter;

import android.content.Context;
import org.json.JSONArray;
import org.json.JSONObject;
import java.io.File;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.nio.charset.StandardCharsets;
import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.Locale;

public final class JobStore {
    private static final Object LOCK = new Object();
    private static final String NAME = "recent-jobs.json";
    private JobStore() {}

    public static JSONArray read(Context context) {
        synchronized (LOCK) {
            File f = new File(context.getFilesDir(), NAME);
            if (!f.exists()) return new JSONArray();
            try (FileInputStream input = new FileInputStream(f)) {
                java.io.ByteArrayOutputStream buffer = new java.io.ByteArrayOutputStream();
                byte[] chunk = new byte[4096];
                int length;
                while ((length = input.read(chunk)) != -1) buffer.write(chunk, 0, length);
                return new JSONArray(new String(buffer.toByteArray(), StandardCharsets.UTF_8));
            } catch (Exception ignored) {
                return new JSONArray();
            }
        }
    }

    public static void add(Context context, String source, byte[] data, EscPosDecoder.Result result) {
        synchronized (LOCK) {
            try {
                JSONArray old = read(context);
                JSONObject record = new JSONObject();
                record.put("time", new SimpleDateFormat("dd/MM HH:mm:ss", Locale.getDefault()).format(new Date()));
                record.put("source", source);
                record.put("text", result.preview);
                record.put("bytes", data.length);
                record.put("cuts", result.cuts);
                StringBuilder hex = new StringBuilder();
                for (int i = 0; i < Math.min(data.length, 512); i++) {
                    if (i != 0) hex.append(' ');
                    hex.append(String.format(Locale.ROOT, "%02X", data[i] & 0xff));
                }
                record.put("hex", hex.toString());
                JSONArray updated = new JSONArray();
                updated.put(record);
                for (int i = 0; i < old.length() && i < 49; i++) updated.put(old.getJSONObject(i));
                File target = new File(context.getFilesDir(), NAME);
                File temp = new File(context.getFilesDir(), NAME + ".tmp");
                try (FileOutputStream out = new FileOutputStream(temp)) {
                    out.write(updated.toString().getBytes(StandardCharsets.UTF_8));
                    out.getFD().sync();
                }
                if (!temp.renameTo(target)) throw new IllegalStateException("Nao salvou historico");
            } catch (Exception ignored) {
                // Nunca interromper a recepcao TCP por falha no historico local.
            }
        }
    }
}
