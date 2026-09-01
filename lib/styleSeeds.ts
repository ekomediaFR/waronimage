export const DEFAULT_STYLES = [
  {
    name: "Local Service Hero",
    description: "Professional team or technician working on-site in the city",
    promptTemplate:
      "Professional {niche} team working in {city}, France. Clean, bright photo style. Brand colors {primary_color}. No text overlay. High quality, photorealistic.",
    niches: ["moving", "locksmith", "plumbing", "storage"],
    formats: { ratio: "16:9", width: 1280, height: 720 },
    exampleUrls: [],
  },
  {
    name: "Service Close-Up",
    description: "Close-up of tools, hands or equipment specific to the niche",
    promptTemplate:
      "Close-up detail shot of {niche} tools and equipment. Professional lighting. Clean white or neutral background. Photorealistic.",
    niches: ["locksmith", "plumbing", "electrical"],
    formats: { ratio: "1:1", width: 800, height: 800 },
    exampleUrls: [],
  },
  {
    name: "City Location Establishing Shot",
    description: "Recognizable city landmark or street with service van/branding",
    promptTemplate:
      "Wide establishing shot of {city} street scene, professional {niche} van parked, daytime, realistic photo.",
    niches: ["moving", "storage"],
    formats: { ratio: "16:9", width: 1280, height: 720 },
    exampleUrls: [],
  },
  {
    name: "Before / After",
    description: "Split-panel showing problem and solution",
    promptTemplate:
      "Split image: left side shows a {niche} problem in a French home, right side shows the professional result after {niche} service. Clean and realistic.",
    niches: ["locksmith", "plumbing", "moving"],
    formats: { ratio: "16:9", width: 1280, height: 720 },
    exampleUrls: [],
  },
];
