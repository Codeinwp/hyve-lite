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

declare global {
	interface Window {
		hyve: HyveData;
	}
}

export {};
