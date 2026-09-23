import http from "k6/http";

export const options = {
  scenarios: {
    browse: { executor: "constant-vus", exec: "browse" },
  },
};

export function setup() {
  return {};
}

export const browse = () => http.get("https://example.test/");

export function unrelated() {
  return 1;
}

export default function () {
  http.get("https://example.test/");
}
