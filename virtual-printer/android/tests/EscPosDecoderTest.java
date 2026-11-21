package br.com.deliveryzim.virtualprinter;
import java.nio.charset.StandardCharsets;
public final class EscPosDecoderTest {
 public static void main(String[] args) {
  byte[] input="2x X-Bacon\nTotal R$ 50,00\n".getBytes(StandardCharsets.US_ASCII);
  byte[] job=new byte[input.length+5];
  job[0]=0x1b; job[1]='@';
  System.arraycopy(input,0,job,2,input.length);
  int i=input.length+2;
  job[i]=0x1d; job[i+1]='V'; job[i+2]=0;
  EscPosDecoder.Result r=EscPosDecoder.decode(job);
  check(r.printable && r.cuts==1,"corte");
  check(r.preview.contains("X-Bacon") && r.preview.contains("50,00"),"texto");
  EscPosDecoder.Result q=EscPosDecoder.decode(new byte[]{0x10,0x04,0x04});
  check(!q.printable && q.statusQueries.size()==1 && q.statusQueries.get(0)==4,"consulta status");
  check(EscPosDecoder.status(4,1)==0x72,"papel");
  check(EscPosDecoder.status(2,2)==0x16,"tampa");
  System.out.println("Testes ESC/POS basicos: OK");
 }
 private static void check(boolean ok,String name){if(!ok)throw new AssertionError(name);}
}
