import { describe, expect, it } from "vitest";
import { buildGif, gifArgs, mp4Args, paletteArgs } from "./gif.mjs";

describe("ffmpeg arguments", () => {
    it("builds the palette from the frames at the GIF's rate and width", () => {
        const args = paletteArgs("f/%04d.png", 8, 1280);
        expect(args[args.indexOf("-vf") + 1]).toBe(
            "fps=8,scale=1280:-1:flags=lanczos,palettegen=stats_mode=diff",
        );
        expect(args[args.indexOf("-framerate") + 1]).toBe("8");
    });
    it("applies the palette with error diffusion, and loops for ever", () => {
        const args = gifArgs("f/%04d.png", "p.png", 8, 1280, "out.gif");
        expect(args[args.indexOf("-lavfi") + 1]).toContain(
            "paletteuse=dither=sierra2_4a",
        );
        expect(args[args.indexOf("-loop") + 1]).toBe("0");
        expect(args.at(-1)).toBe("out.gif");
    });
    it("encodes the MP4 in a pixel format browsers play", () => {
        const args = mp4Args("f/%04d.png", 8, "out.mp4");
        expect(args[args.indexOf("-pix_fmt") + 1]).toBe("yuv420p");
        expect(args.at(-1)).toBe("out.mp4");
    });
});

describe("buildGif", () => {
    const fake = (size) => {
        const runs = [];
        return {
            runs,
            options: {
                maxBytes: 5_000_000,
                runFfmpeg: async (args) => runs.push(args),
                statSize: async () => size,
            },
        };
    };
    it("makes the palette, then the GIF, and stops there when the GIF fits", async () => {
        const { runs, options } = fake(4_000_000);
        const built = await buildGif("frames", "out/hero.gif", options);
        expect(built).toEqual({ gif: "out/hero.gif", bytes: 4_000_000 });
        expect(runs).toHaveLength(2);
        expect(runs[0].at(-1)).toBe("frames/palette.png");
        expect(runs[1]).toContain("frames/%05d.png");
        expect(runs[1].at(-1)).toBe("out/hero.gif");
    });
    it("adds an MP4 beside a GIF larger than maxBytes", async () => {
        const { runs, options } = fake(6_000_000);
        const built = await buildGif("frames", "out/hero.gif", options);
        expect(built).toEqual({
            gif: "out/hero.gif",
            bytes: 6_000_000,
            mp4: "out/hero.mp4",
        });
        expect(runs).toHaveLength(3);
        expect(runs[2].at(-1)).toBe("out/hero.mp4");
    });
});
