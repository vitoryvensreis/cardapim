package br.com.deliveryzim.virtualscale;

import java.nio.charset.StandardCharsets;
import java.util.Arrays;

public class ScaleProtocolTest {
    private static void eq(byte[] expected, byte[] actual) {
        if (!Arrays.equals(expected, actual)) throw new AssertionError(
                "Esperado " + ScaleProtocol.hex(expected) + ", obtido " + ScaleProtocol.hex(actual));
    }

    public static void main(String[] args) {
        eq("ST,GS,+0.350kg\r\n".getBytes(StandardCharsets.US_ASCII),
                ScaleProtocol.frame(350, true, false, ScaleProtocol.ASCII));
        eq("US,GS,+1.251kg\r\n".getBytes(StandardCharsets.US_ASCII),
                ScaleProtocol.frame(1251, false, false, ScaleProtocol.ASCII));
        eq(new byte[]{2, '0','0','3','5','0',3}, ScaleProtocol.frame(350, true, false, ScaleProtocol.TOLEDO_PRT5));
        eq(new byte[]{2, 'I','I','I','I','I',3}, ScaleProtocol.frame(350, false, false, ScaleProtocol.TOLEDO_PRT5));
        eq(new byte[]{2, 'S','S','S','S','S',3}, ScaleProtocol.frame(350, true, true, ScaleProtocol.URANO_PROT3));
        eq(new byte[]{2, '9','9','9','9','9',3}, ScaleProtocol.frame(99999,true,false,ScaleProtocol.URANO_PROT3));
        try { ScaleProtocol.frame(100000,true,false,ScaleProtocol.ASCII); throw new AssertionError(); }
        catch (IllegalArgumentException expected) { /* correto */ }
        System.out.println("Protocolos ASCII, Toledo Prt5 e Urano PROT3: OK.");
    }
}
