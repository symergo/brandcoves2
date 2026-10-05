"""Pack favicon-16/32/48.png into one favicon.ico. Run by scripts/favicons.mjs."""
import sys

from PIL import Image

source, target = sys.argv[1], sys.argv[2]
images = [Image.open(f'{source}/favicon-{size}.png').convert('RGBA') for size in (16, 32, 48)]

# Pillow writes every size in `sizes` from the first image it is given, so the
# largest goes first and the smaller ones ride along as their own frames.
images[2].save(target, format='ICO', sizes=[(16, 16), (32, 32), (48, 48)], append_images=images[:2])
print(target)
