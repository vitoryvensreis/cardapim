package br.com.deliveryzim.virtualprinter;

import java.io.ByteArrayOutputStream;
import java.nio.charset.Charset;
import java.util.ArrayList;
import java.util.List;

/** Interpretador ESC/POS parcial para testes, independente de Android. */
public final class EscPosDecoder {
    private EscPosDecoder() { }

    public static final class Result {
        public final String preview;
        public final int cuts;
        public final List<Integer> statusQueries;
        public final boolean printable;
        Result(String preview, int cuts, List<Integer> queries, boolean printable) {
            this.preview = preview;
            this.cuts = cuts;
            this.statusQueries = queries;
            this.printable = printable;
        }
    }

    public static Result decode(byte[] raw) {
        StringBuilder preview = new StringBuilder();
        ByteArrayOutputStream text = new ByteArrayOutputStream();
        List<Integer> queries = new ArrayList<>();
        int cuts = 0;
        Charset cp850 = Charset.forName("IBM850");
        boolean meaningful = false;
        for (int i = 0; i < raw.length; i++) {
            int b = raw[i] & 0xff;
            if (b == 0x10 && i + 2 < raw.length && (raw[i + 1] & 0xff) == 0x04) {
                flush(text, preview, cp850);
                queries.add(raw[i + 2] & 0xff);
                i += 2;
            } else if (b == 0x1b && i + 1 < raw.length) {
                flush(text, preview, cp850);
                int cmd = raw[++i] & 0xff;
                if (cmd == '@' || cmd == '2') {
                    // Inicializa ou restaura espacamento.
                } else if ((cmd == 'a' || cmd == 'E' || cmd == '!' || cmd == 't' || cmd == '3' || cmd == 'J' || cmd == 'd') && i + 1 < raw.length) {
                    i++;
                } else if (cmd == '*' && i + 3 < raw.length) {
                    int nL = raw[i + 2] & 0xff;
                    int nH = raw[i + 3] & 0xff;
                    i += 3;
                    i = Math.min(raw.length - 1, i + nL + nH * 256);
                    preview.append("[IMAGEM]\n");
                    meaningful = true;
                }
            } else if (b == 0x1d && i + 1 < raw.length) {
                flush(text, preview, cp850);
                int cmd = raw[++i] & 0xff;
                if (cmd == 'V' && i + 1 < raw.length) {
                    int mode = raw[++i] & 0xff;
                    if ((mode == 65 || mode == 66) && i + 1 < raw.length) i++;
                    preview.append("\n-------- CORTE --------\n");
                    cuts++;
                    meaningful = true;
                } else if ((cmd == '!' || cmd == 'B' || cmd == 'H' || cmd == 'h' || cmd == 'w') && i + 1 < raw.length) {
                    i++;
                } else if (cmd == '(' && i + 3 < raw.length && (raw[i + 1] & 0xff) == 'k') {
                    i++;
                    int size = (raw[i + 1] & 0xff) | ((raw[i + 2] & 0xff) << 8);
                    i += 2;
                    i = Math.min(raw.length - 1, i + size);
                    preview.append("[DADOS QR]\n");
                    meaningful = true;
                } else if (cmd == 'v' && i + 5 < raw.length && (raw[i + 1] & 0xff) == '0') {
                    i += 2;
                    int x = (raw[i + 1] & 0xff) | ((raw[i + 2] & 0xff) << 8);
                    int y = (raw[i + 3] & 0xff) | ((raw[i + 4] & 0xff) << 8);
                    i += 4;
                    i = Math.min(raw.length - 1, i + x * y);
                    preview.append("[IMAGEM]\n");
                    meaningful = true;
                }
            } else if (b == 0x0a) {
                flush(text, preview, cp850);
                preview.append('\n');
                meaningful = true;
            } else if (b == 0x0d) {
                // Ignora CR: acompanham LF em varios drivers.
            } else if (b == 0x09) {
                text.write(' '); text.write(' ');
            } else if (b >= 0x20) {
                text.write(b);
                meaningful = true;
            }
        }
        flush(text, preview, cp850);
        return new Result(preview.toString().trim(), cuts, queries, meaningful);
    }

    private static void flush(ByteArrayOutputStream bytes, StringBuilder into, Charset encoding) {
        if (bytes.size() > 0) {
            into.append(new String(bytes.toByteArray(), encoding));
            bytes.reset();
        }
    }

    public static int status(int n, int faultMode) {
        if (n == 2 && faultMode == 2) return 0x16; // Tampa aberta
        if (n == 4 && faultMode == 1) return 0x72; // Sem papel
        if (n == 1 && faultMode == 3) return 0x1a; // Offline
        return 0x12; // Pronta
    }
}
