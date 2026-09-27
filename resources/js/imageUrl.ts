/**
 * Our own resized WebP copies of a merchant's picture (docs/features/image-proxy.md).
 *
 * The server hands out a signed `imageToken` beside `image` for each picture
 * the proxy may serve, and null for one it may not (proxy switched off, a host
 * not on its list, Amazon). Only the server can sign, so the browser never
 * builds an address for a picture the server did not vouch for; it only picks
 * the width.
 *
 * The widths must match `giftcoves.image_proxy.widths`: any other is a 404.
 */
export const IMAGE_WIDTHS = [160, 320, 480, 640, 960] as const

export type ImageWidth = (typeof IMAGE_WIDTHS)[number]

/** The proxied address at one width. */
export function imageUrl(token: string, width: ImageWidth): string {
    return `/img/${width}/${token}`
}

/** A `srcset` over the given widths, for the browser to pick from with `sizes`. */
export function imageSrcSet(token: string, widths: readonly ImageWidth[] = IMAGE_WIDTHS): string {
    return widths.map((w) => `${imageUrl(token, w)} ${w}w`).join(', ')
}

/**
 * The attributes for one picture: the proxied copies when there is a token and
 * they have not failed, the shop's own URL otherwise.
 *
 * `proxyFailed` is the caller's state, set from `onError`: the proxy already
 * redirects to the original when it cannot make a copy, so this is the second
 * net, for the proxy route itself being unreachable.
 */
export function pictureAttributes(
    src: string,
    token: string | null | undefined,
    proxyFailed: boolean,
    fallbackWidth: ImageWidth,
    sizes: string,
    widths: readonly ImageWidth[] = IMAGE_WIDTHS,
): { src: string; srcSet?: string; sizes?: string } {
    if (!token || proxyFailed) {
        return { src }
    }

    return { src: imageUrl(token, fallbackWidth), srcSet: imageSrcSet(token, widths), sizes }
}
