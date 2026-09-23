import { greet } from "#shared/greet";
import { util } from "@/util";

export const checked = [1, 2].map((value) => util(value));
export const first: number = [1, 2][0];
export const greeting = greet("you");
