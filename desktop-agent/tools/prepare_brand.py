"""Valida os assets oficiais e gera o ícone de EXE/taskbar do Windows."""
from pathlib import Path
from PIL import Image

ASSETS = Path(__file__).resolve().parents[1] / "agent" / "assets"


def prepare():
    for name in ("icone.png", "logo-branca.png"):
        with Image.open(ASSETS / name) as img:
            img.verify()  # Falha no CI se o arquivo oficial estiver truncado.
    with Image.open(ASSETS / "icone.png") as source:
        image = source.convert("RGBA").resize((256, 256), Image.Resampling.LANCZOS)
        destination = ASSETS / "deliveryzim.ico"
        image.save(destination, format="ICO", sizes=[
            (16, 16), (24, 24), (32, 32), (48, 48), (64, 64), (128, 128), (256, 256)
        ])
        with Image.open(destination) as icon:
            icon.verify()
        print("Ícone oficial preparado para o executável:", destination)


if __name__ == "__main__":
    prepare()
