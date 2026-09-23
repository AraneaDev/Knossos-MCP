import { afterEach, describe, expect, it } from "vitest";
import fs from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";

import { TypeScriptScanner } from "../scanner.js";

// NestJS calls a method decorated with @Cron, @Interval, @OnEvent,
// @EventPattern and the like on its own schedule or event, and Passport calls a
// strategy's validate(). Nothing in the project names any of them, so each read
// as unreferenced while running in production.

const created = [];

afterEach(() => {
    while (created.length > 0) {
        fs.rmSync(created.pop(), { recursive: true, force: true });
    }
});

function fixture(files) {
    const root = fs.realpathSync(
        fs.mkdtempSync(join(tmpdir(), "knossos-ts-nest-")),
    );
    created.push(root);
    for (const [path, contents] of Object.entries(files)) {
        fs.mkdirSync(dirname(join(root, path)), { recursive: true });
        fs.writeFileSync(join(root, path), contents);
    }
    return root;
}

describe("NestJS methods the framework calls", () => {
    it("carry a framework handler role", () => {
        const root = fixture({
            "src/jobs.ts": [
                'import { Injectable } from "@nestjs/common";',
                'import { Cron, Interval } from "@nestjs/schedule";',
                'import { OnEvent } from "@nestjs/event-emitter";',
                'import { PassportStrategy } from "@nestjs/passport";',
                "declare const Strategy: new () => object;",
                "@Injectable()",
                "export class Cleanup {",
                '    @Cron("0 3 * * *") cleanup(): void {}',
                "    @Interval(1000) tick(): void {}",
                '    @OnEvent("user.created") welcome(): void {}',
                "    helper(): void {}",
                "}",
                "export class JwtStrategy extends PassportStrategy(Strategy) {",
                "    validate(): object { return {}; }",
                "    other(): void {}",
                "}",
                "",
            ].join("\n"),
        });
        const contributions = [];
        new TypeScriptScanner().scan({ root, files: ["src/jobs.ts"] }, (c) =>
            contributions.push(c),
        );
        const roles = Object.fromEntries(
            contributions
                .flatMap((c) => c.nodes)
                .filter((n) => n.kind === "method")
                .map((n) => [
                    n.canonical_name,
                    n.attributes.nestjs_roles ?? [],
                ]),
        );

        for (const handler of [
            "src/jobs.ts#Cleanup::cleanup",
            "src/jobs.ts#Cleanup::tick",
            "src/jobs.ts#Cleanup::welcome",
            "src/jobs.ts#JwtStrategy::validate",
        ])
            expect(roles[handler]).toContain("nestjs.framework_handler");
        expect(roles["src/jobs.ts#Cleanup::helper"]).toEqual([]);
        expect(roles["src/jobs.ts#JwtStrategy::other"]).toEqual([]);
    });
});
