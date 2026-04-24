import { useEffect, useRef, useState } from "react";

interface Props {
	phrases: string[];
	/** ms per char while typing */
	typeMs?: number;
	/** ms per char while deleting */
	deleteMs?: number;
	/** ms pause at end of a fully-typed phrase before starting to delete */
	holdMs?: number;
	/** ms pause after deletion before typing the next phrase */
	gapMs?: number;
}

export default function TypewriterHeadline({
	phrases,
	typeMs = 60,
	deleteMs = 35,
	holdMs = 1800,
	gapMs = 400,
}: Props) {
	const [text, setText] = useState(phrases[0] ?? "");
	const [phraseIdx, setPhraseIdx] = useState(0);
	const [phase, setPhase] = useState<"hold" | "deleting" | "typing">("hold");
	const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

	useEffect(() => {
		if (!phrases.length) return;
		const current = phrases[phraseIdx];

		const clear = () => {
			if (timerRef.current) clearTimeout(timerRef.current);
		};

		if (phase === "hold") {
			timerRef.current = setTimeout(() => setPhase("deleting"), holdMs);
		} else if (phase === "deleting") {
			if (text.length === 0) {
				timerRef.current = setTimeout(() => {
					setPhraseIdx((i) => (i + 1) % phrases.length);
					setPhase("typing");
				}, gapMs);
			} else {
				timerRef.current = setTimeout(
					() => setText((t) => t.slice(0, -1)),
					deleteMs,
				);
			}
		} else if (phase === "typing") {
			if (text.length === current.length) {
				setPhase("hold");
			} else {
				timerRef.current = setTimeout(
					() => setText(current.slice(0, text.length + 1)),
					typeMs,
				);
			}
		}

		return clear;
	}, [phrases, phraseIdx, phase, text, typeMs, deleteMs, holdMs, gapMs]);

	if (!phrases.length) return null;

	return (
		<span
			className="typewriter"
			aria-live="polite"
			aria-label={phrases[phraseIdx]}
		>
			<span>{text}</span>
			<span className="typewriter-caret" aria-hidden="true">
				|
			</span>
		</span>
	);
}
