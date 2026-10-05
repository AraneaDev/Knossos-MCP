/**
 * Dev-only. Builds the hero GIF from numbered PNG frames with ffmpeg's two-pass
 * palette (palettegen, then paletteuse), and an MP4 beside it when the GIF
 * comes out larger than the README can carry.
 */
import { execFile } from "node:child_process";
import { stat } from "node:fs/promises";
import path from "node:path";
import { promisify } from "node:util";

const run = promisify(execFile);

export const paletteArgs = (frames, fps, width) => [
    "-y",
    "-framerate",
    String(fps),
    "-i",
    frames,
    "-vf",
    `fps=${fps},scale=${width}:-1:flags=lanczos,palettegen=stats_mode=diff`,
];

export const gifArgs = (frames, palette, fps, width, out) => [
    "-y",
    "-framerate",
    String(fps),
    "-i",
    frames,
    "-i",
    palette,
    "-lavfi",
    `fps=${fps},scale=${width}:-1:flags=lanczos[x];[x][1:v]paletteuse=dither=sierra2_4a`,
    "-loop",
    "0",
    out,
];

export const mp4Args = (frames, fps, out) => [
    "-y",
    "-framerate",
    String(fps),
    "-i",
    frames,
    "-c:v",
    "libx264",
    "-pix_fmt",
    "yuv420p",
    "-movflags",
    "+faststart",
    out,
];

export async function buildGif(
    dir,
    out,
    {
        fps = 8,
        width = 1280,
        maxBytes = 5_000_000,
        runFfmpeg = (args) =>
            run("ffmpeg", args, { maxBuffer: 64 * 1024 * 1024 }),
        statSize = async (f) => (await stat(f)).size,
    } = {},
) {
    const frames = path.join(dir, "%05d.png");
    const palette = path.join(dir, "palette.png");
    await runFfmpeg([...paletteArgs(frames, fps, width), palette]);
    await runFfmpeg(gifArgs(frames, palette, fps, width, out));
    const bytes = await statSize(out);
    if (bytes <= maxBytes) return { gif: out, bytes };
    const mp4 = out.replace(/\.gif$/, ".mp4");
    await runFfmpeg(mp4Args(frames, fps, mp4));
    return { gif: out, bytes, mp4 };
}
