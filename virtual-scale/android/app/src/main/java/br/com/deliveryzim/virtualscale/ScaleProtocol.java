package br.com.deliveryzim.virtualscale;

import java.nio.charset.StandardCharsets;
import java.util.Locale;

/** Perfis documentados de comunicação; nunca converte simulação em medição real. */
public final class ScaleProtocol {
    public static final int ASCII = 0;
    public static final int TOLEDO_PRT5 = 1;
    public static final int URANO_PROT3 = 2;
    public static final int PORT = 9101;

    private ScaleProtocol() {}

    public static byte[] frame(int grams, boolean stable, boolean overload, int profile) {
        if (grams < 0 || grams > 99999) throw new IllegalArgumentException("Faixa permitida: 0 a 99,999 kg");
        if (profile == ASCII) {
            if (overload) return "OL\r\n".getBytes(StandardCharsets.US_ASCII);
            String sign = stable ? "ST" : "US";
            return String.format(Locale.ROOT, "%s,GS,+%d.%03dkg\r\n",
                    sign, grams / 1000, grams % 1000).getBytes(StandardCharsets.US_ASCII);
        }
        if (profile != TOLEDO_PRT5 && profile != URANO_PROT3) {
            throw new IllegalArgumentException("Protocolo não suportado");
        }
        String payload = overload ? "SSSSS" : stable
                ? String.format(Locale.ROOT, "%05d", grams) : "IIIII";
        return ("\u0002" + payload + "\u0003").getBytes(StandardCharsets.US_ASCII);
    }

    public static String hex(byte[] bytes) {
        StringBuilder builder = new StringBuilder();
        for (byte b : bytes) {
            if (builder.length() > 0) builder.append(' ');
            builder.append(String.format(Locale.ROOT, "%02X", b & 255));
        }
        return builder.toString();
    }
}
