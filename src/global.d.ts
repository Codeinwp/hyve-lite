interface PostType {
	label: string;
	value: string;
}

interface HyveAssets {
	images: string;
}

interface HyveChart {
	legend: {
		messagesLabel: string;
		sessionsLabel: string;
	};
	data: {
		messages: number[];
		sessions: number[];
	};
	labels: string[];
}

interface HyveData {
	api: string;
	postTypes: PostType[];
	hasAPIKey: boolean;
	chunksLimit: number;
	isQdrantActive: boolean;
	assets: HyveAssets;
	stats: any; // Type this more specifically if you know the structure
	docs: string;
	qdrant_docs: string;
	pro: string;
	chart: HyveChart;
}

interface HyveAudio {
	click: string;
	ping: string;
}

interface HyveStrings {
	reply: string;
	suggestions: string;
	tryAgain: string;
	typing: string;
	clearConversation: string;
}

interface HyveColors {
	chat_background: boolean;
	assistant_background: boolean;
	user_background: boolean;
	icon_background: boolean;
}

interface HyveClient {
	api: string;
	audio: HyveAudio;
	welcome: string;
	isEnabled: string;
	strings: HyveStrings;
	predefinedQuestions: string[];
	colors: HyveColors;
	icons: Record< string, string >;
	chatIcon: {
		type: string;
		value: string;
	};
}

declare global {
	interface Window {
		hyve: HyveData;
		hyveClient: HyveClient;
	}
}

export {};
